<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BrandController extends Controller
{
    public function index()
    {
        $brands = Brand::withCount('documents')->orderBy('name')->get();
        return view('admin.brands.index', compact('brands'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:brands,code'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        Brand::create([
            'name' => $validated['name'],
            'code' => $validated['code'] ?: Str::upper(Str::slug($validated['name'], '_')),
            'description' => $validated['description'] ?? null,
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        return back()->with('status', 'Brand added.');
    }

    public function toggleStatus(Brand $brand)
    {
        $brand->update([
            'status' => $brand->status === 'active' ? 'inactive' : 'active',
            'updated_by' => request()->user()->id,
        ]);

        return back()->with('status', "Brand \"{$brand->name}\" is now {$brand->status}.");
    }
}
