<?php

declare(strict_types=1);

namespace Modules\Analyzers\Actions;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\Analyzers\Models\Machine;
use Modules\Analyzers\Models\Message;
use Modules\Analyzers\Permissions;
use Modules\Analyzers\Support\AnalyzerException;
use Modules\Laboratory\Permissions as LabPermissions;

/**
 * Adding machines and their keys. A key is shown once, when it is made;
 * only its hash is kept, so a lost key is replaced, never read back.
 */
final class ManageMachines
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, mixed> $data @return array{0: Machine, 1: string} the machine and its new key */
    public function add(array $data): array
    {
        Gate::authorize(Permissions::MACHINES_MANAGE);
        $this->checkOperator((int) ($data['user_id'] ?? 0), (int) ($data['branch_id'] ?? 0));

        return DB::connection('clinic')->transaction(function () use ($data): array {
            [$key, $hash] = $this->newKey();

            $machine = new Machine([
                'name' => trim((string) $data['name']),
                'model' => filled($data['model'] ?? null) ? trim((string) $data['model']) : null,
                'protocol' => (string) $data['protocol'],
                'branch_id' => (int) $data['branch_id'],
                'user_id' => (int) $data['user_id'],
                'is_active' => true,
            ]);
            $machine->forceFill(['token_hash' => $hash, 'token_hint' => substr($key, -4)])->save();

            $this->audit->record('analyzer.machine_added', $machine, [], $machine->only(['name', 'model', 'protocol', 'branch_id', 'user_id']));

            return [$machine, $key];
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Machine $machine, array $data): Machine
    {
        Gate::authorize(Permissions::MACHINES_MANAGE);
        $this->checkOperator((int) $data['user_id'], (int) $data['branch_id']);

        return DB::connection('clinic')->transaction(function () use ($machine, $data): Machine {
            $before = $machine->only(['name', 'model', 'protocol', 'branch_id', 'user_id', 'is_active']);
            $machine->fill([
                'name' => trim((string) $data['name']),
                'model' => filled($data['model'] ?? null) ? trim((string) $data['model']) : null,
                'protocol' => (string) $data['protocol'],
                'branch_id' => (int) $data['branch_id'],
                'user_id' => (int) $data['user_id'],
                'is_active' => (bool) ($data['is_active'] ?? true),
            ])->save();
            [$was, $is] = $this->audit->diff($before, $machine->only(array_keys($before)));

            if ($is !== []) {
                $this->audit->record('analyzer.machine_updated', $machine, $was, $is);
            }

            return $machine;
        });
    }

    /** A new key; the old one stops working at once. */
    public function replaceKey(Machine $machine): string
    {
        Gate::authorize(Permissions::MACHINES_MANAGE);

        [$key, $hash] = $this->newKey();
        $machine->forceFill(['token_hash' => $hash, 'token_hint' => substr($key, -4)])->save();
        $this->audit->record('analyzer.key_replaced', $machine, [], ['hint' => substr($key, -4)]);

        return $key;
    }

    public function findByKey(string $key): ?Machine
    {
        if (! preg_match('/^anz_[A-Za-z0-9]{40}$/', $key)) {
            return null;
        }

        return Machine::where('token_hash', hash('sha256', $key))->where('is_active', true)->first();
    }

    /** Staff types the sample number for results that did not find their sample. */
    public function rematch(Message $message, string $sampleCode): Message
    {
        Gate::authorize(Permissions::INBOX_RESOLVE);

        if (blank($sampleCode)) {
            throw new AnalyzerException('Type the sample number from the label.');
        }
        if ($message->status === 'failed') {
            throw new AnalyzerException('This message could not be read, so it cannot be matched.');
        }

        $message = app(ReceiveResults::class)->rematch($message, $sampleCode);
        $this->audit->record('analyzer.rematched', $message, [], ['sample' => trim($sampleCode), 'status' => $message->status]);

        return $message;
    }

    /** @return array{0: string, 1: string} */
    private function newKey(): array
    {
        $key = 'anz_'.Str::random(40);

        return [$key, hash('sha256', $key)];
    }

    private function checkOperator(int $userId, int $branchId): void
    {
        $user = User::where('is_active', true)->find($userId);

        if ($user === null || ! Gate::forUser($user)->allows(LabPermissions::RESULTS_ENTER, $branchId)) {
            throw new AnalyzerException('Choose an active lab account that may enter results in this branch. The machine\'s results are saved under it.');
        }
    }
}
