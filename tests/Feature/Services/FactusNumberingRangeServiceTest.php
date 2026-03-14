<?php

namespace Tests\Feature\Services;

use App\Models\FactusNumberingRange;
use App\Services\FactusApiService;
use App\Services\FactusNumberingRangeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class FactusNumberingRangeServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('factus_numbering_ranges');

        Schema::create('factus_numbering_ranges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('factus_id')->unique();
            $table->string('document')->nullable();
            $table->string('document_code')->nullable();
            $table->string('prefix')->nullable();
            $table->unsignedBigInteger('range_from')->nullable();
            $table->unsignedBigInteger('range_to')->nullable();
            $table->unsignedBigInteger('current')->nullable();
            $table->string('resolution_number')->nullable();
            $table->text('technical_key')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_expired')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    #[Test]
    public function it_syncs_credit_note_ranges_returned_with_numeric_document_codes(): void
    {
        $factusApi = Mockery::mock(FactusApiService::class);
        $factusApi->shouldReceive('get')
            ->once()
            ->with('/v1/numbering-ranges', [
                'filter' => ['is_active' => 1],
            ])
            ->andReturn([
                'data' => [
                    'data' => [
                        [
                            'id' => 1288,
                            'document' => '22',
                            'document_name' => 'Nota Crédito',
                            'prefix' => 'NCW',
                            'from' => null,
                            'to' => null,
                            'current' => 1,
                            'resolution_number' => null,
                            'technical_key' => null,
                            'start_date' => null,
                            'end_date' => null,
                            'is_expired' => false,
                            'is_active' => 1,
                        ],
                    ],
                ],
            ]);

        $service = new FactusNumberingRangeService($factusApi);

        $synced = $service->sync();

        $this->assertSame(1, $synced);

        $range = FactusNumberingRange::query()->where('factus_id', 1288)->firstOrFail();

        $this->assertSame('Nota Crédito', $range->document);
        $this->assertSame('22', $range->document_code);
        $this->assertSame('NCW', $range->prefix);
        $this->assertTrue($range->isCreditNoteRange());
    }
}
