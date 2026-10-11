<?php

namespace App\Http\Controllers;

use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BusinessTypeController extends Controller
{
    /**
     * Display all business types (public + admin).
     */
    public function index()
{
    $types = BusinessType::ordered()->get();

    // Always return JSON for now (API-first)
    return response()->json([
        'count' => $types->count(),
        'data'  => $types,
    ]);
}

    /**
     * Show form to create a new type (admin only).
     */
    public function create()
    {
        return view('business-types.create');
    }

    /**
     * Store a new business type (admin only).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:255|unique:business_types,name',
            'icon'        => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $type = BusinessType::create($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Business type created successfully.',
                'data'    => $type,
            ], 201);
        }

        return redirect()
            ->route('business-types.index')
            ->with('success', 'Business type created successfully.');
    }

    /**
     * Show a single business type.
     */
    public function show(BusinessType $businessType)
    {
        if (request()->wantsJson()) {
            return response()->json($businessType);
        }

        return view('business-types.show', compact('businessType'));
    }

    /**
     * Show edit form (admin only).
     */
    public function edit(BusinessType $businessType)
    {
        return view('business-types.edit', compact('businessType'));
    }

    /**
     * Update a business type (admin only).
     */
    public function update(Request $request, BusinessType $businessType)
    {
        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:255',
                              Rule::unique('business_types', 'name')->ignore($businessType->id)],
            'icon'        => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'is_active'   => 'boolean',
            'sort_order'  => 'nullable|integer|min:0',
        ]);

        $validated['slug'] = Str::slug($validated['name']);
        $validated['is_active'] = $request->boolean('is_active', true);

        $businessType->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Business type updated successfully.',
                'data'    => $businessType,
            ]);
        }

        return redirect()
            ->route('business-types.index')
            ->with('success', 'Business type updated successfully.');
    }

    /**
     * Delete a business type (admin only).
     */
    public function destroy(BusinessType $businessType)
    {
        $businessType->delete();

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Business type deleted successfully.']);
        }

        return redirect()
            ->route('business-types.index')
            ->with('success', 'Business type deleted successfully.');
    }
}