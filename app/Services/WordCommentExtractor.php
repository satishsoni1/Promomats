<?php

namespace App\Services;

use App\Models\DocumentVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use XMLReader;
use ZipArchive;

/**
 * Reads the comments embedded in a Word (.docx) file - the ones reviewers add in
 * the in-browser Word editor or in desktop Word - so the job page can list them
 * version by version next to the platform's own comments (MOM 24 Nov: "show
 * comments embedded in Word documents ... comments to be displayed version-wise").
 *
 * Versions are immutable, so each version's result is cached.
 */
class WordCommentExtractor
{
    protected const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    protected const W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';

    protected const W15 = 'http://schemas.microsoft.com/office/word/2012/wordml';

    /**
     * @return array<int, array{id: string, author: string, date: ?string, text: string, anchor: ?string, is_reply: bool, done: bool}>
     */
    public function forVersion(DocumentVersion $version): array
    {
        if (strtolower(pathinfo($version->original_filename ?? $version->file_path, PATHINFO_EXTENSION)) !== 'docx') {
            return [];
        }

        return Cache::rememberForever("word-comments:v1:{$version->id}:{$version->checksum_sha256}", function () use ($version) {
            try {
                return $this->extractFromVersion($version);
            } catch (\Throwable $e) {
                Log::warning('Could not read Word comments', ['version_id' => $version->id, 'error' => $e->getMessage()]);

                return [];
            }
        });
    }

    protected function extractFromVersion(DocumentVersion $version): array
    {
        $disk = Storage::disk($version->disk);
        if (! $disk->exists($version->file_path)) {
            return [];
        }

        $isLocal = config("filesystems.disks.{$version->disk}.driver") === 'local';
        $path = $isLocal ? $disk->path($version->file_path) : tempnam(sys_get_temp_dir(), 'docx');

        try {
            if (! $isLocal) {
                file_put_contents($path, $disk->get($version->file_path));
            }

            return $this->extract($path);
        } finally {
            if (! $isLocal && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** Comments from a .docx on local disk, in document order. */
    public function extract(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return [];
        }

        try {
            $commentsXml = $zip->getFromName('word/comments.xml');
            if ($commentsXml === false) {
                return [];
            }

            $comments = $this->parseComments($commentsXml);
            if ($comments === []) {
                return [];
            }

            if (($extended = $zip->getFromName('word/commentsExtended.xml')) !== false) {
                $this->applyThreading($comments, $extended);
            }
            if (($body = $zip->getFromName('word/document.xml')) !== false) {
                $anchors = $this->parseAnchors($body);
                foreach ($comments as $id => &$comment) {
                    $comment['anchor'] = $anchors[$id] ?? null;
                }
                unset($comment);
            }

            return array_values(array_map(fn ($c) => array_diff_key($c, ['para_ids' => 1]), $comments));
        } finally {
            $zip->close();
        }
    }

    /** @return array<string, array<string, mixed>> keyed by w:id */
    protected function parseComments(string $xml): array
    {
        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);

        $comments = [];
        $current = null;
        $paragraphs = [];
        $para = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->namespaceURI === self::W) {
                if ($reader->localName === 'comment') {
                    $current = [
                        'id' => (string) $reader->getAttributeNs('id', self::W),
                        'author' => (string) ($reader->getAttributeNs('author', self::W) ?: 'Unknown'),
                        'date' => $reader->getAttributeNs('date', self::W) ?: null,
                        'text' => '',
                        'anchor' => null,
                        'is_reply' => false,
                        'done' => false,
                        'para_ids' => [],
                    ];
                    $paragraphs = [];
                } elseif ($reader->localName === 'p' && $current !== null) {
                    $para = '';
                    if ($pid = $reader->getAttributeNs('paraId', self::W14)) {
                        $current['para_ids'][] = $pid;
                    }
                    if ($reader->isEmptyElement) {
                        $paragraphs[] = '';
                    }
                } elseif ($reader->localName === 't' && $current !== null) {
                    $para .= $reader->readString();
                } elseif (in_array($reader->localName, ['tab', 'br'], true) && $current !== null) {
                    $para .= ' ';
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT && $reader->namespaceURI === self::W && $current !== null) {
                if ($reader->localName === 'p') {
                    $paragraphs[] = $para;
                } elseif ($reader->localName === 'comment') {
                    $current['text'] = trim(implode("\n", array_filter(array_map('trim', $paragraphs), 'strlen')));
                    $comments[$current['id']] = $current;
                    $current = null;
                }
            }
        }
        $reader->close();

        return $comments;
    }

    /** Word 2013+ threads: a reply's last paragraph names its parent's paragraph. */
    protected function applyThreading(array &$comments, string $xml): void
    {
        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $byPara = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'commentEx' && $reader->namespaceURI === self::W15) {
                $byPara[(string) $reader->getAttributeNs('paraId', self::W15)] = [
                    'parent' => $reader->getAttributeNs('paraIdParent', self::W15),
                    'done' => $reader->getAttributeNs('done', self::W15) === '1',
                ];
            }
        }
        $reader->close();

        foreach ($comments as &$comment) {
            $last = end($comment['para_ids']) ?: null;
            if ($last && isset($byPara[$last])) {
                $comment['is_reply'] = (bool) $byPara[$last]['parent'];
                $comment['done'] = $byPara[$last]['done'];
            }
        }
        unset($comment);
    }

    /** The text each comment is attached to (between its range start and end). */
    protected function parseAnchors(string $xml): array
    {
        $reader = new XMLReader();
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE);
        $open = [];
        $text = [];

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->namespaceURI !== self::W) {
                continue;
            }
            $name = $reader->localName;
            if ($name === 'commentRangeStart') {
                $open[(string) $reader->getAttributeNs('id', self::W)] = true;
            } elseif ($name === 'commentRangeEnd') {
                unset($open[(string) $reader->getAttributeNs('id', self::W)]);
            } elseif ($name === 't' && $open) {
                $chunk = $reader->readString();
                foreach (array_keys($open) as $id) {
                    $text[$id] = ($text[$id] ?? '') . $chunk;
                }
            }
        }
        $reader->close();

        return array_map(fn ($t) => mb_strimwidth(trim(preg_replace('/\s+/', ' ', $t)), 0, 300, '…'), $text);
    }
}
