<?php

namespace App\Http\Controllers\StrayReports;

use App\Http\Controllers\Controller;
use App\Http\Requests\StrayReports\ListStrayReportsRequest;
use App\Http\Requests\StrayReports\SaveStrayReportRequest;
use App\Http\Resources\StrayReportResource;
use App\Models\StrayReport;
use App\Services\StrayReports\StrayReportService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class StrayReportController extends Controller
{
    public function index(ListStrayReportsRequest $request): AnonymousResourceCollection
    {
        // Hide reports from accounts that are no longer active or verified.
        $query = StrayReport::query()->whereHas('user', fn (Builder $user) =>
            $user->where('is_active', true)->whereNotNull('email_verified_at'));

        return $this->listing($request, $query);
    }

    public function mine(ListStrayReportsRequest $request): AnonymousResourceCollection
    {
        return $this->listing($request, StrayReport::query()->where('user_id', $request->user()->id));
    }

    public function store(SaveStrayReportRequest $request, StrayReportService $service): JsonResponse
    {
        $report = $service->create($request->user(), $request->validated(), $request->file('photos'));

        return (new StrayReportResource($report))
            ->additional(['message' => 'Stray report created successfully.'])
            ->response()->setStatusCode(201);
    }

    public function show(StrayReport $strayReport): StrayReportResource
    {
        abort_unless($strayReport->user()->where('is_active', true)->whereNotNull('email_verified_at')->exists(), 404);

        return new StrayReportResource($strayReport->load('photos'));
    }

    public function update(SaveStrayReportRequest $request, StrayReport $strayReport, StrayReportService $service): StrayReportResource
    {
        // Ownership is checked by SaveStrayReportRequest before validation.
        return (new StrayReportResource($service->update($strayReport, $request->validated())))
            ->additional(['message' => 'Stray report updated successfully.']);
    }

    public function destroy(StrayReport $strayReport, StrayReportService $service): JsonResponse
    {
        Gate::authorize('delete', $strayReport);
        $service->delete($strayReport);

        return response()->json(['message' => 'Stray report deleted successfully.']);
    }

    public function status(Request $request, StrayReport $strayReport, StrayReportService $service): StrayReportResource
    {
        Gate::authorize('update', $strayReport);
        $data = $request->validate(['status' => ['required', Rule::in(StrayReport::STATUSES)]]);

        return (new StrayReportResource($service->changeStatus($strayReport, $data['status'])))
            ->additional(['message' => 'Report status updated successfully.']);
    }

    public function addPhotos(Request $request, StrayReport $strayReport, StrayReportService $service): StrayReportResource
    {
        Gate::authorize('update', $strayReport);
        $request->validate(SaveStrayReportRequest::photoRules());

        return (new StrayReportResource($service->addPhotos($strayReport, $request->file('photos'))))
            ->additional(['message' => 'Photos added successfully.']);
    }

    public function deletePhoto(StrayReport $strayReport, int $photo, StrayReportService $service): JsonResponse
    {
        Gate::authorize('update', $strayReport);
        $service->deletePhoto($strayReport, $photo);

        return response()->json(['message' => 'Photo deleted successfully.']);
    }

    private function listing(ListStrayReportsRequest $request, Builder $query): AnonymousResourceCollection
    {
        $data = $request->validated();
        foreach (['pet_type', 'gender', 'status', 'animal_condition'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }
        foreach (['breed' => 'breed', 'color' => 'color', 'location' => 'location_name'] as $key => $column) {
            if (isset($data[$key])) {
                // Column names are fixed above; all user input is bound as a value.
                $query->whereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($data[$key]).'%']);
            }
        }
        if (isset($data['q'])) {
            $term = '%'.mb_strtolower($data['q']).'%';
            $query->where(function (Builder $search) use ($term) {
                foreach (['pet_name', 'breed', 'color', 'description', 'distinguishing_features', 'location_name'] as $column) {
                    $search->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            });
        }

        return StrayReportResource::collection($query->with('photos')->orderByDesc('created_at')
            ->orderByDesc('id')->paginate((int) ($data['per_page'] ?? 15))->withQueryString());
    }
}