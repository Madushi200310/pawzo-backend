<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
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

        $data = $request->validate([
            'admin_id' => ['sometimes', 'required', 'integer', 'min:1'],
            'method' => [
                'sometimes',
                'required',
                Rule::in(['POST', 'PUT', 'PATCH', 'DELETE']),
            ],
            'response_status' => [
                'sometimes',
                'required',
                'integer',
                'between:100,599',
            ],
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => [
                'sometimes',
                'required',
                'date_format:Y-m-d',
                Rule::when($request->filled('from'), 'after_or_equal:from'),
            ],
            'per_page' => ['sometimes', 'integer', 'between:1,50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $query = AdminAuditLog::query();

        foreach (['admin_id', 'method', 'response_status'] as $field) {
            if (isset($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        if (isset($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }

        if (isset($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($data['per_page'] ?? 15))
                ->withQueryString()
        );
    }
}