<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit Trail — baca activity_log yang sudah dicatat lewat LogsActivity di
 * beberapa model (Kavling, KavlingKonsumen, Konsumen, BastRecord,
 * CancellationRequest). Read-only, tidak ada aksi tulis di sini.
 */
class AuditTrailController extends Controller
{
    /** Label Indonesia untuk nama model — dipetakan dari class FQN subject_type. */
    private const SUBJECT_LABELS = [
        'App\\Models\\Kavling'             => 'Kavling',
        'App\\Models\\KavlingKonsumen'     => 'Transaksi Konsumen',
        'App\\Models\\Konsumen'            => 'Konsumen',
        'App\\Models\\BastRecord'          => 'BAST',
        'App\\Models\\CancellationRequest' => 'Pengajuan Pembatalan/Tukar Unit',
    ];

    private const EVENT_LABELS = [
        'created' => 'Dibuat',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
    ];

    public function index(Request $request): Response
    {
        abort_unless(Auth::user()->can('view audit trail'), 403);

        $rows = Activity::query()
            ->with('causer:id,name')
            ->when($request->causer_id, fn ($q) => $q->where('causer_id', $request->causer_id))
            ->when($request->subject_type, fn ($q) => $q->where('subject_type', $request->subject_type))
            ->when($request->event, fn ($q) => $q->where('event', $request->event))
            ->when($request->from, fn ($q) => $q->whereDate('created_at', '>=', $request->from))
            ->when($request->to, fn ($q) => $q->whereDate('created_at', '<=', $request->to))
            ->when($request->search, fn ($q) => $q->where('description', 'like', "%{$request->search}%"))
            ->orderByDesc('id')
            ->paginate($this->perPage($request, 50))
            ->withQueryString()
            ->through(fn (Activity $a) => [
                'id'            => $a->id,
                'waktu'         => $a->created_at->format('d M Y H:i'),
                'causer_nama'   => $a->causer?->name ?? 'Sistem',
                'event'         => $a->event,
                'event_label'   => self::EVENT_LABELS[$a->event] ?? $a->event,
                'description'   => $a->description,
                'subject_type'  => $a->subject_type,
                'subject_label' => self::SUBJECT_LABELS[$a->subject_type] ?? class_basename($a->subject_type ?? ''),
                'subject_id'    => $a->subject_id,
                'changes'       => $this->formatChanges($a->getAttribute('attribute_changes')?->toArray()),
            ]);

        return Inertia::render('AuditTrail/Index', [
            'rows'    => $rows,
            'filters' => $request->only(['causer_id', 'subject_type', 'event', 'from', 'to', 'search']),
            'filterOptions' => [
                'users'    => User::orderBy('name')->get(['id', 'name']),
                'subjects' => collect(self::SUBJECT_LABELS)->map(fn ($label, $class) => ['value' => $class, 'label' => $label])->values(),
                'events'   => collect(self::EVENT_LABELS)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values(),
            ],
        ]);
    }

    /**
     * Ubah {old, attributes} jadi list per-field [nama, dari, ke] yang gampang
     * dirender — hanya field yang benar-benar berbeda (logOnlyDirty() di tiap
     * model biasanya sudah begitu, tapi jaga-jaga).
     */
    private function formatChanges(?array $raw): array
    {
        if (!$raw || !isset($raw['attributes'])) {
            return [];
        }

        $old = $raw['old'] ?? [];
        $new = $raw['attributes'];

        $result = [];
        foreach ($new as $field => $value) {
            $before = $old[$field] ?? null;
            if ($before === $value) continue;
            $result[] = ['field' => $field, 'dari' => $before, 'ke' => $value];
        }

        return $result;
    }
}
