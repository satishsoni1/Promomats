<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Country master (MOM 24 Nov): the document's Country dropdown. The list comes
 * from Himalaya - paste it in one go with "Bulk add", one country per line.
 */
class CountryController extends Controller
{
    public function index()
    {
        $countries = Country::withCount('documents')->orderBy('name')->get();

        return view('admin.countries.index', compact('countries'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:countries,name'],
            'code' => ['nullable', 'string', 'max:10', 'alpha', 'unique:countries,code'],
        ]);

        Country::create(['name' => trim($validated['name']), 'code' => $this->code($validated['code'] ?? null), 'status' => 'active']);

        return back()->with('status', 'Country added.');
    }

    public function update(Request $request, Country $country)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('countries', 'name')->ignore($country->id)],
            'code' => ['nullable', 'string', 'max:10', 'alpha', Rule::unique('countries', 'code')->ignore($country->id)],
        ]);

        $country->update(['name' => trim($validated['name']), 'code' => $this->code($validated['code'] ?? null)]);

        return back()->with('status', "Country \"{$country->name}\" updated.");
    }

    /** One per line: "India" or "India, IN" (name, optional ISO code). Existing ones are skipped. */
    public function bulkStore(Request $request)
    {
        $request->validate(['lines' => ['required', 'string', 'max:100000']]);

        $added = 0;
        $skipped = 0;
        foreach (preg_split('/\r\n|\r|\n/', $request->input('lines')) as $line) {
            [$name, $code] = array_pad(array_map('trim', str_getcsv($line)), 2, null);
            if (blank($name)) {
                continue;
            }
            $code = $this->code($code);
            if (Country::where('name', $name)->exists() || ($code && Country::where('code', $code)->exists())) {
                $skipped++;

                continue;
            }
            Country::create(['name' => $name, 'code' => $code, 'status' => 'active']);
            $added++;
        }

        return back()->with('status', "{$added} country(ies) added" . ($skipped ? ", {$skipped} already in the list" : '') . '.');
    }

    public function toggleStatus(Country $country)
    {
        $country->update(['status' => $country->status === 'active' ? 'inactive' : 'active']);

        return back()->with('status', "Country \"{$country->name}\" is now {$country->status}.");
    }

    protected function code(?string $code): ?string
    {
        $code = strtoupper(trim((string) $code));

        return $code !== '' ? $code : null;
    }
}
