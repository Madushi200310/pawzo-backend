<?php

namespace App\Http\Controllers\Health;

use App\Http\Controllers\Controller;
use App\Http\Requests\Health\StoreHealthRecordRequest;
use App\Http\Requests\Health\UpdateHealthRecordRequest;
use App\Models\HealthDocument;
use App\Models\HealthRecord;
use App\Models\Pet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HealthRecordController extends Controller
{
    /**
     * List health records for a pet.
     */
    public function index(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $query = $pet->healthRecords()->with('documents')->orderByDesc('date')->orderByDesc('id');

        if ($request->filled('record_type')) {
            $query->where('record_type', $request->string('record_type'));
        }

        return response()->json([
            'message' => 'Health records fetched successfully.',
            'records' => $query->get(),
        ]);
    }

    /**
     * Create a new health record.
     */
    public function store(StoreHealthRecordRequest $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $record = $pet->healthRecords()->create($request->validated());

        return response()->json([
            'message' => 'Health record created successfully.',
            'record'  => $record->load('documents'),
        ], 201);
    }

    /**
     * Show a single health record.
     */
    public function show(Request $request, Pet $pet, HealthRecord $health_record): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($health_record, $pet);

        return response()->json([
            'message' => 'Health record fetched successfully.',
            'record'  => $health_record->load('documents'),
        ]);
    }

    /**
     * Update a health record.
     */
    public function update(UpdateHealthRecordRequest $request, Pet $pet, HealthRecord $health_record): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($health_record, $pet);

        $health_record->update($request->validated());

        return response()->json([
            'message' => 'Health record updated successfully.',
            'record'  => $health_record->fresh()->load('documents'),
        ]);
    }

    /**
     * Soft-delete a health record (documents remain, but record is hidden).
     */
    public function destroy(Request $request, Pet $pet, HealthRecord $health_record): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($health_record, $pet);

        $health_record->delete();

        return response()->json([
            'message' => 'Health record deleted successfully.',
        ]);
    }

    /**
     * Summary counts grouped by record_type for a pet.
     */
    public function summary(Request $request, Pet $pet): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);

        $counts = $pet->healthRecords()
            ->selectRaw('record_type, COUNT(*) as total')
            ->groupBy('record_type')
            ->pluck('total', 'record_type')
            ->toArray();

        // Ensure all types appear (with 0 fallback)
        $summary = [];
        foreach (HealthRecord::TYPES as $type) {
            $summary[$type] = $counts[$type] ?? 0;
        }

        return response()->json([
            'message' => 'Health record summary fetched successfully.',
            'summary' => $summary,
            'total'   => array_sum($summary),
        ]);
    }

    // ---------------- Documents ----------------

    /**
     * Upload one or more documents for a health record.
     */
    public function uploadDocuments(Request $request, Pet $pet, HealthRecord $health_record): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($health_record, $pet);

        $request->validate([
            'documents'   => ['required', 'array', 'min:1', 'max:5'],
            'documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'], // 5 MB each
        ]);

        $existing = $health_record->documents()->count();
        $incoming = count($request->file('documents'));

        if ($existing + $incoming > 5) {
            throw ValidationException::withMessages([
                'documents' => ["A record can have at most 5 documents. You currently have {$existing}."],
            ]);
        }

        $created = [];

        foreach ($request->file('documents') as $file) {
            $path = $file->store("health-documents/{$health_record->id}", 'public');

            $created[] = $health_record->documents()->create([
                'path'          => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type'     => $file->getClientMimeType(),
                'size'          => $file->getSize(),
            ]);
        }

        return response()->json([
            'message'   => 'Documents uploaded successfully.',
            'documents' => $created,
            'record'    => $health_record->fresh()->load('documents'),
        ], 201);
    }

    /**
     * Delete a document.
     */
    public function destroyDocument(Request $request, Pet $pet, HealthRecord $health_record, HealthDocument $document): JsonResponse
    {
        $this->authorizeOwnership($request, $pet);
        $this->ensureBelongsToPet($health_record, $pet);

        if ($document->health_record_id !== $health_record->id) {
            throw ValidationException::withMessages([
                'document' => ['This document does not belong to this record.'],
            ]);
        }

        if (Storage::disk('public')->exists($document->path)) {
            Storage::disk('public')->delete($document->path);
        }
        $document->delete();

        return response()->json([
            'message' => 'Document deleted successfully.',
            'record'  => $health_record->fresh()->load('documents'),
        ]);
    }

    // ---------------- Helpers ----------------

    private function authorizeOwnership(Request $request, Pet $pet): void
    {
        if ($pet->user_id !== $request->user()->id) {
            abort(403, 'You do not have permission to access this pet.');
        }
    }

    private function ensureBelongsToPet(HealthRecord $record, Pet $pet): void
    {
        if ($record->pet_id !== $pet->id) {
            throw ValidationException::withMessages([
                'record' => ['This health record does not belong to this pet.'],
            ]);
        }
    }
}