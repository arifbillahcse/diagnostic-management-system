<?php

declare(strict_types=1);

namespace Modules\Imaging\Tests;

use App\Core\Modules\ModuleManager;
use Modules\FrontDesk\Enums\ServiceType;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\Service;
use Modules\FrontDesk\Tests\FrontDeskTestCase;
use Modules\Imaging\Actions\WriteReport;
use Modules\Imaging\Models\Study;
use Modules\Imaging\Models\Template;

/** Front desk set-up with the imaging module on and an ultrasound service. */
abstract class ImagingTestCase extends FrontDeskTestCase
{
    protected const BASE = 'http://alpha.test';

    protected Service $usg;

    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('imaging');
        $this->usg = $this->service('USG whole abdomen', '1200', ServiceType::Imaging);
        $this->actingAs($this->ownerUser);
    }

    protected function usgOrder(): Order
    {
        return $this->order([$this->usg]);
    }

    protected function study(?Order $order = null): Study
    {
        return Study::where('order_id', ($order ?? $this->usgOrder())->id)->firstOrFail();
    }

    protected function template(string $name = 'Normal', string $sex = 'any'): Template
    {
        return Template::create([
            'service_id' => $this->usg->id, 'name' => $name, 'sex' => $sex,
            'findings' => '<p><strong>Liver:</strong> Normal.</p>', 'impression' => '<p>Normal study.</p>',
        ]);
    }

    /** @param array<string, mixed> $text */
    protected function sign(Study $study, array $text = []): Study
    {
        return app(WriteReport::class)->sign($study, $text + ['findings' => '<p>Liver normal.</p>', 'impression' => '<p>Normal study.</p>'], $study->fresh()->lock_version);
    }
}
