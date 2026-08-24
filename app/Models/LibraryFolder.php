<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibraryFolder extends Model
{
    protected $fillable = ['name', 'parent_id', 'created_by'];

    public function parent()
    {
        return $this->belongsTo(LibraryFolder::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(LibraryFolder::class, 'parent_id')->orderBy('name');
    }

    public function files()
    {
        return $this->hasMany(ReferenceAttachment::class, 'library_folder_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Root-to-self breadcrumb trail, e.g. [Studies, Immunobooster, VX-204] for a
     * folder three levels deep.
     */
    public function breadcrumbs(): array
    {
        $trail = [];
        $node = $this;
        while ($node) {
            array_unshift($trail, $node);
            $node = $node->parent;
        }

        return $trail;
    }

    public function isEmpty(): bool
    {
        return $this->children()->doesntExist() && $this->files()->doesntExist();
    }
}
