<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\CashOutflow;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use App\Enums\ShiftHandoverStatus;

class CashOutflowManager extends Component
{
    use WithPagination;

    // Filtros
    public $date;
    public $search = '';

    // Form data for creating new outflow
    public $showCreateModal = false;
    public $amount;
    public $reason;
    public $outflow_date;

    protected $queryString = [
        'date' => ['except' => ''],
        'search' => ['except' => ''],
    ];

    protected $rules = [
        'amount' => 'required|numeric|min:0.01',
        'reason' => 'required|string|min:3|max:255',
        'outflow_date' => 'required|date',
    ];

    protected $messages = [
        'amount.required' => 'El monto es obligatorio.',
        'amount.numeric' => 'El monto debe ser un número.',
        'amount.min' => 'El monto debe ser mayor a cero.',
        'reason.required' => 'El motivo es obligatorio.',
        'reason.min' => 'El motivo debe tener al menos 3 caracteres.',
        'outflow_date.required' => 'La fecha es obligatoria.',
    ];

    public function mount()
    {
        $this->date = request('date') ?: now()->format('Y-m-d');
        $this->outflow_date = now()->format('Y-m-d');
    }

    public function updatedAmount()
    {
        $this->amount = $this->sanitizeNumber($this->amount);
    }

    public function updatedSearch()
    {
        $this->resetPage();
    }

    public function updatedDate()
    {
        $this->resetPage();
    }

    public function openCreateModal()
    {
        $this->resetErrorBag();
        $this->reset(['amount', 'reason']);
        $this->outflow_date = now()->format('Y-m-d');
        $this->showCreateModal = true;
    }

    private function sanitizeNumber($value)
    {
        if (empty($value)) return 0;

        // Si ya es un número (int o float), lo devolvemos tal cual
        if (is_int($value) || is_float($value)) return (float)$value;

        // Si es un string, quitamos puntos de miles y cambiamos coma por punto decimal
        $clean = str_replace('.', '', (string)$value);
        $clean = str_replace(',', '.', $clean);

        return is_numeric($clean) ? (float)$clean : 0;
    }

    public function saveOutflow()
    {
        $this->validate();

        $user = Auth::user();
        $activeShift = $user->turnoActivo()->first();
        if (!$activeShift) {
            $this->addError('amount', "Debe haber un turno operativo abierto para registrar la salida de dinero.");
            return;
        }

        // VALIDACIÓN DE SALDO DISPONIBLE
        $disponible = $activeShift->getEfectivoDisponible();
        if ($this->amount > $disponible) {
            $this->addError('amount', "Saldo insuficiente en caja. Disponible: $" . number_format($disponible, 0, ',', '.'));
            return;
        }

        $outflow = CashOutflow::create([
            'user_id' => $user->id,
            'shift_handover_id' => $activeShift->id,
            'amount' => $this->amount,
            'reason' => $this->reason,
            'date' => $this->outflow_date,
        ]);

        // Actualizar totales del turno activo si existe
        if ($activeShift) {
            $activeShift->updateTotals();
        }

        $this->auditLog('cash_outflow_create', "Salida de dinero registrada por {$this->amount}. Motivo: {$this->reason}", ['outflow_id' => $outflow->id]);

        $this->showCreateModal = false;
        session()->flash('success', 'Salida de dinero registrada correctamente.');
    }

    public function deleteOutflow($id)
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $outflow = CashOutflow::findOrFail($id);

        $this->auditLog('cash_outflow_delete', "Eliminó salida de dinero #{$id}. Motivo: {$outflow->reason}. Monto era: {$outflow->amount}", [
            'outflow_id' => $id,
            'amount' => $outflow->amount,
            'reason' => $outflow->reason,
            'deleted_by' => $user->name,
            'role' => $user->roles->first()->name ?? 'N/A'
        ]);

        $outflow->delete();

        // Recalcular turno si aplica
        $activeShift = $user->turnoActivo()->first();
        if ($activeShift) {
            $activeShift->updateTotals();
        }

        session()->flash('success', 'Registro eliminado correctamente y registrado en auditoría.');
    }

    private function auditLog($event, $description, $metadata = [])
    {
        AuditLog::create([
            'user_id' => Auth::id(),
            'event' => $event,
            'description' => $description,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'metadata' => $metadata
        ]);
    }

    public function render()
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $activeShift = $user->turnoActivo()->first();
        $isAdmin = $user->hasRole('Administrador');
        $deleteWindowMinutes = (int) config('shifts.cash_delete_window_minutes', 60);

        $query = CashOutflow::with(['user', 'shiftHandover']);

        // Recepcionistas: ver solo sus propios gastos de su turno activo
        if (!$isAdmin) {
            $query->where('user_id', $user->id);
            if ($activeShift) {
                $query->where('shift_handover_id', $activeShift->id);
            }
        }

        if ($isAdmin && $activeShift) {
            $query->where('shift_handover_id', $activeShift->id);
        } elseif ($this->date) {
            $query->whereDate('date', $this->date);
        }

        if ($this->search) {
            $query->where('reason', 'like', '%' . $this->search . '%');
        }

        $outflows = $query->latest('date')->paginate(10);
        $totalOutflows = (clone $query)->sum('amount');

        return view('livewire.cash-outflow-manager', [
            'outflows' => $outflows,
            'totalOutflows' => $totalOutflows,
            'activeShiftId' => $activeShift ? $activeShift->id : null,
            'activeShiftStatus' => $activeShift ? $activeShift->status->value : null,
            'deleteWindowMinutes' => $deleteWindowMinutes,
            'isAdmin' => $isAdmin,
            'activeShift' => $activeShift,
        ])->extends('layouts.app')
          ->section('content');
    }
}

