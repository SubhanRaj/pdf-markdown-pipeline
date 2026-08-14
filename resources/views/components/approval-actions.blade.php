<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Requests\ApproveDocumentRequest;
use App\Http\Requests\RejectDocumentRequest;
use App\Http\Requests\ReclassifyDocumentRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

// Invoked from approvals/index.blade.php's existing vanilla JS via Livewire.first()
// instead of raw fetch() — the drawer/modal/table/JSON-island code is untouched, only
// the network/auth transport for the five actions below changed.
new class extends Component
{
    public function approve(int $id, ?string $note = null): array
    {
        return $this->run(fn () => app(ApprovalController::class)->approve(
            $id, $this->formRequest(ApproveDocumentRequest::class, ['note' => $note])
        ));
    }

    public function reject(int $id, string $reason): array
    {
        return $this->run(fn () => app(ApprovalController::class)->reject(
            $id, $this->formRequest(RejectDocumentRequest::class, ['reason' => $reason])
        ));
    }

    public function reclassify(int $id, array $data): array
    {
        return $this->run(fn () => app(ApprovalController::class)->reclassify(
            $id, $this->formRequest(ReclassifyDocumentRequest::class, $data)
        ));
    }

    public function resubmit(int $id): array
    {
        return $this->run(fn () => app(ApprovalController::class)->resubmit($id));
    }

    // Loops the same single-document call, same as the old bulkApprove/bulkReject JS —
    // there is still no dedicated bulk backend endpoint.
    public function bulkApprove(array $ids, ?string $note = null): array
    {
        $failed = 0;
        foreach ($ids as $id) {
            if (! $this->approve((int) $id, $note)['ok']) $failed++;
        }
        return ['ok' => true, 'failed' => $failed];
    }

    public function bulkReject(array $ids, string $reason): array
    {
        $failed = 0;
        foreach ($ids as $id) {
            if (! $this->reject((int) $id, $reason)['ok']) $failed++;
        }
        return ['ok' => true, 'failed' => $failed];
    }

    // Authorization failures (abort(403) in the controller) propagate uncaught, same as
    // pipeline-monitor's convert() — the client only ever offers these actions when
    // can_act is true, so a 403 here means stale state, not a normal user path.
    private function run(\Closure $action): array
    {
        try {
            $action();
            return ['ok' => true];
        } catch (ValidationException $e) {
            return ['ok' => false, 'message' => collect($e->errors())->flatten()->first()];
        }
    }

    // Builds a real FormRequest via the same createFrom()+validateResolved() path Laravel
    // uses when resolving one from a route — reuses ApproveDocumentRequest/RejectDocumentRequest/
    // ReclassifyDocumentRequest's authorize()/rules() verbatim instead of re-deriving them.
    private function formRequest(string $class, array $data): FormRequest
    {
        $request = $class::createFrom(request(), new $class());
        $request->setContainer(app())->setRedirector(app('redirect'));
        $request->merge($data);
        $request->validateResolved();

        return $request;
    }
}; ?>

<div></div>
