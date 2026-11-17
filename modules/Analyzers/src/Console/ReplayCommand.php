<?php

declare(strict_types=1);

namespace Modules\Analyzers\Console;

use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Modules\Analyzers\Actions\ReceiveResults;
use Modules\Analyzers\Models\Machine;

/**
 * The simulator: feeds a file of recorded machine output to a machine as if
 * the bridge had sent it, so mappings can be tried without the machine.
 * Messages in the file are separated by a line holding only "---".
 */
final class ReplayCommand extends Command
{
    protected $signature = 'analyzers:replay
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {machine : The machine\'s name}
        {file : A text file of recorded messages, separated by lines of ---}';

    protected $description = 'Feed recorded analyzer output to a machine, as the bridge would, to test mappings without the machine';

    public function handle(ClinicManager $manager): int
    {
        $clinic = Clinic::find((int) $this->argument('clinic'));
        $file = (string) $this->argument('file');

        if ($clinic === null || ! is_file($file)) {
            $this->error($clinic === null ? 'There is no clinic with that ID.' : 'The file does not exist.');

            return self::FAILURE;
        }

        $messages = array_values(array_filter(array_map('trim', preg_split('/^---\s*$/m', (string) file_get_contents($file)) ?: []), fn ($m) => $m !== ''));

        return $manager->run($clinic, function () use ($messages): int {
            if (! app(ModuleState::class)->isEnabled('analyzers')) {
                $this->error('Lab machine integration is switched off for this clinic.');

                return self::FAILURE;
            }

            $machine = Machine::where('name', $this->argument('machine'))->first();

            if ($machine === null) {
                $this->error('There is no machine with that name.');

                return self::FAILURE;
            }

            foreach ($messages as $i => $raw) {
                $message = app(ReceiveResults::class)->receive($machine, str_replace("\n", "\r", $raw));
                $saved = $message->results()->where('status', 'applied')->count();
                $this->line(sprintf('%d. %s: %s, %d value(s) saved, %d not saved', $i + 1, $message->sample_codes ?? 'no sample', $message->status, $saved, $message->results()->count() - $saved));
            }

            return self::SUCCESS;
        });
    }
}
