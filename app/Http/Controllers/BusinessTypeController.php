<?php

namespace App\Http\Controllers;

use App\Models\BusinessType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessTypeController extends Controller
{
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        abort_unless($user, 401);

        abort_unless(
            $user->isAdmin()
                && $user->is_active
                && $user->email_verified_at !== null,
            403,
            'An active admin account with a verified email is required.',
        );
    }

    private function adminRoute(Request $request): bool
    {
        return $request->is('api/admin/*');
    }

    public function index(Request $request)
    {
        $query = BusinessType::query()->ordered();

        if ($this->adminRoute($request)) {
            $this->authorizeAdmin($request);
        } else {
            $query->active();
        }

        $types = $query->get();

        return response()->json([
            'count' => $types->count(),
            'data' => $types,
        ]);
    }

    public function create(Request $request)
    {
        $this->authorizeAdmin($request);

        return view('business-types.create');
    }

    public function store(Request $request)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => [
                'required', 'string', 'max:255',
                Rule::unique('business_types', 'name'),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => [
                'sometimes', 'nullable', 'string', 'max:2000',
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        $data['slug'] = $this->validatedSlug($data['name']);
        $data['is_active'] = $data['is_active'] ?? true;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        $type = BusinessType::create($data);

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Business type created successfully.',
                'data' => $type,
            ], 201);
        }

        return redirect()->route('business-types.index')
            ->with('success', 'Business type created successfully.');
    }

    public function show(Request $request, BusinessType $businessType)
    {
        if ($this->adminRoute($request)) {
            $this->authorizeAdmin($request);
        } else {
            abort_unless($businessType->is_active, 404);
        }

        if ($request->wantsJson() || $request->is('api/*')) {
            // Preserve the existing business-type show response.
            return response()->json($businessType);
        }

        return view('business-types.show', compact('businessType'));
    }

    public function edit(Request $request, BusinessType $businessType)
    {
        $this->authorizeAdmin($request);

        return view('business-types.edit', compact('businessType'));
    }

    public function update(Request $request, BusinessType $businessType)
    {
        $this->authorizeAdmin($request);

        $data = $request->validate([
            'name' => [
                'sometimes', 'required', 'string', 'max:255',
                Rule::unique('business_types', 'name')
                    ->ignore($businessType->id),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => [
                'sometimes', 'nullable', 'string', 'max:2000',
            ],
            'is_active' => ['sometimes', 'required', 'boolean'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
        ]);

        if (isset($data['name'])) {
            $data['slug'] = $this->validatedSlug(
                $data['name'],
                $businessType->id,
            );
        }

        $businessType->update($data);

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Business type updated successfully.',
                'data' => $businessType->fresh(),
            ]);
        }

        return redirect()->route('business-types.index')
            ->with('success', 'Business type updated successfully.');
    }

    public function destroy(Request $request, BusinessType $businessType)
    {
        $this->authorizeAdmin($request);

        DB::transaction(function () use ($businessType) {
            $locked = BusinessType::query()
                ->lockForUpdate()
                ->findOrFail($businessType->id);

            if ($locked->businesses()->exists()) {
                throw ValidationException::withMessages([
                    'business_type' => [
                        'This type is used by businesses. Deactivate it instead.',
                    ],
                ]);
            }

            $locked->delete();
        });

        if ($request->wantsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Business type deleted successfully.',
            ]);
        }

        return redirect()->route('business-types.index')
            ->with('success', 'Business type deleted successfully.');
    }

    private function validatedSlug(string $name, ?int $ignoreId = null): string
    {
        $slug = Str::slug($name);

        $query = BusinessType::where('slug', $slug);

        if ($ignoreId !== null) {
            $query->where('id', '!=', $ignoreId);
        }

        if ($slug === '' || $query->exists()) {
            throw ValidationException::withMessages([
                'name' => [
                    'Choose a name that produces a non-empty, unique URL slug.',
                ],
            ]);
        }

        return $slug;
    }
}