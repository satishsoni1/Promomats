<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Sku;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * SKU master (MOM 24 Nov): "Product" became "SKU", and the document's SKU dropdown
 * is filtered by the selected brand. Himalaya's Brand & SKU list can be pasted in
 * one go ("Brand, SKU" per line) - brands that don't exist yet are created.
 */
class SkuController extends Controller
{
    public function index(Request $request)
    {
        $brands = Brand::orderBy('name')->get(['id', 'name', 'status']);
        $skus = Sku::with('brand')->withCount('documents')
            ->when($request->filled('brand'), fn ($q) => $q->where('brand_id', $request->brand))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($s) => $s
                ->where('name', 'like', "%{$request->search}%")
                ->orWhere('code', 'like', "%{$request->search}%")))
            ->orderBy(Brand::select('name')->whereColumn('brands.id', 'skus.brand_id'))
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('admin.skus.index', compact('brands', 'skus'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('skus', 'name')->where('brand_id', $request->input('brand_id'))],
            'code' => ['nullable', 'string', 'max:100'],
        ]);

        Sku::create($validated + ['status' => 'active', 'created_by' => $request->user()->id]);

        return back()->with('status', 'SKU added.');
    }

    public function update(Request $request, Sku $sku)
    {
        $validated = $request->validate([
            'brand_id' => ['required', 'exists:brands,id'],
            'name' => ['required', 'string', 'max:255', Rule::unique('skus', 'name')->where('brand_id', $request->input('brand_id'))->ignore($sku->id)],
            'code' => ['nullable', 'string', 'max:100'],
        ]);

        $sku->update($validated);

        return back()->with('status', "SKU \"{$sku->name}\" updated.");
    }

    /** One per line: "Brand, SKU" or "Brand, SKU, SKU code". */
    public function bulkStore(Request $request)
    {
        $request->validate(['lines' => ['required', 'string', 'max:200000']]);

        $added = 0;
        $skipped = 0;
        $newBrands = 0;
        $bad = [];

        foreach (preg_split('/\r\n|\r|\n/', $request->input('lines')) as $n => $line) {
            if (blank($line)) {
                continue;
            }
            [$brandName, $skuName, $code] = array_pad(array_map('trim', str_getcsv($line)), 3, null);
            if (blank($brandName) || blank($skuName)) {
                $bad[] = $n + 1;

                continue;
            }

            $brand = Brand::where('name', $brandName)->orWhere('code', $brandName)->first();
            if (! $brand) {
                $brand = Brand::create([
                    'name' => $brandName,
                    'code' => $this->uniqueBrandCode($brandName),
                    'status' => 'active',
                    'created_by' => $request->user()->id,
                ]);
                $newBrands++;
            }

            if ($brand->skus()->where('name', $skuName)->exists()) {
                $skipped++;

                continue;
            }

            $brand->skus()->create(['name' => $skuName, 'code' => $code ?: null, 'status' => 'active', 'created_by' => $request->user()->id]);
            $added++;
        }

        $message = "{$added} SKU(s) added" . ($newBrands ? ", {$newBrands} new brand(s) created" : '') . ($skipped ? ", {$skipped} already in the list" : '') . '.';
        if ($bad) {
            $message .= ' Skipped line(s) ' . implode(', ', array_slice($bad, 0, 20)) . ' - each line needs "Brand, SKU".';
        }

        return back()->with('status', $message);
    }

    public function toggleStatus(Sku $sku)
    {
        $sku->update(['status' => $sku->status === 'active' ? 'inactive' : 'active']);

        return back()->with('status', "SKU \"{$sku->name}\" is now {$sku->status}.");
    }

    protected function uniqueBrandCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '_')) ?: 'BRAND';
        $code = $base;
        for ($i = 2; Brand::where('code', $code)->exists(); $i++) {
            $code = "{$base}_{$i}";
        }

        return $code;
    }
}
