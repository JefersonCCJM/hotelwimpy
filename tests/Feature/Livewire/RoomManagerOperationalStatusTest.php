<?php

namespace Tests\Feature\Livewire;

use App\Livewire\RoomManager;
use App\Models\Room;
use App\Services\RoomAvailabilityService;
use App\Support\HotelTime;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoomManagerOperationalStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateTestingSchema();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function daily_observation_is_saved_for_the_current_operational_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 04:30:00', 'America/Bogota'));

        $operationalDate = HotelTime::currentOperationalDate();
        $room = $this->createRoom('101');

        $component = new RoomManager();
        $component->date = $operationalDate->copy();
        $component->currentDate = $operationalDate->copy();

        $component->saveRoomDailyObservation($room->id, 'Revisar minibar antes del cierre');

        $this->assertTrue(
            DB::table('room_operational_statuses')
                ->where('room_id', $room->id)
                ->whereDate('operational_date', '2026-03-06')
                ->where('observation', 'Revisar minibar antes del cierre')
                ->exists()
        );

        $this->assertSame(
            'Revisar minibar antes del cierre',
            $room->fresh()->dailyObservation($operationalDate)
        );

        $this->assertNull(
            $room->fresh()->dailyObservation($operationalDate->copy()->addDay())
        );
    }

    #[Test]
    public function maintenance_blocks_the_selected_operational_day_and_the_next_day_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 10:00:00', 'America/Bogota'));

        $operationalDate = HotelTime::currentOperationalDate();
        $room = $this->createRoom('201');

        $component = new RoomManager();
        $component->date = $operationalDate->copy();
        $component->currentDate = $operationalDate->copy();

        $component->updateCleaningStatus($room->id, 'mantenimiento');

        $freshRoom = $room->fresh();

        $this->assertTrue(
            DB::table('room_operational_statuses')
                ->where('room_id', $room->id)
                ->whereDate('operational_date', $operationalDate->toDateString())
                ->where('cleaning_override_status', 'mantenimiento')
                ->whereDate('maintenance_source_date', $operationalDate->toDateString())
                ->exists()
        );

        $this->assertTrue(
            DB::table('room_operational_statuses')
                ->where('room_id', $room->id)
                ->whereDate('operational_date', $operationalDate->copy()->addDay()->toDateString())
                ->where('cleaning_override_status', 'mantenimiento')
                ->whereDate('maintenance_source_date', $operationalDate->toDateString())
                ->exists()
        );

        $this->assertSame('mantenimiento', $freshRoom->cleaningStatus($operationalDate)['code']);
        $this->assertSame('mantenimiento', $freshRoom->cleaningStatus($operationalDate->copy()->addDay())['code']);
        $this->assertSame('limpia', $freshRoom->cleaningStatus($operationalDate->copy()->addDays(2))['code']);

        $availability = new RoomAvailabilityService();

        $this->assertFalse(
            $availability->isRoomAvailableForDates(
                $room->id,
                $operationalDate->copy(),
                $operationalDate->copy()->addDays(2)
            )
        );

        $this->assertTrue(
            $availability->isRoomAvailableForDates(
                $room->id,
                $operationalDate->copy()->addDays(2),
                $operationalDate->copy()->addDays(3)
            )
        );
    }

    #[Test]
    public function reapplying_maintenance_on_the_next_day_extends_the_block_one_more_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 10:00:00', 'America/Bogota'));

        $dayOne = HotelTime::currentOperationalDate();
        $room = $this->createRoom('301');

        $component = new RoomManager();
        $component->date = $dayOne->copy();
        $component->currentDate = $dayOne->copy();
        $component->updateCleaningStatus($room->id, 'mantenimiento');

        $dayTwoComponent = new RoomManager();
        $dayTwoComponent->date = $dayOne->copy()->addDay();
        $dayTwoComponent->currentDate = $dayOne->copy()->addDay();
        $dayTwoComponent->updateCleaningStatus($room->id, 'mantenimiento');

        $this->assertTrue(
            DB::table('room_operational_statuses')
                ->where('room_id', $room->id)
                ->whereDate('operational_date', $dayOne->copy()->addDay()->toDateString())
                ->where('cleaning_override_status', 'mantenimiento')
                ->whereDate('maintenance_source_date', $dayOne->copy()->addDay()->toDateString())
                ->exists()
        );

        $this->assertTrue(
            DB::table('room_operational_statuses')
                ->where('room_id', $room->id)
                ->whereDate('operational_date', $dayOne->copy()->addDays(2)->toDateString())
                ->where('cleaning_override_status', 'mantenimiento')
                ->whereDate('maintenance_source_date', $dayOne->copy()->addDay()->toDateString())
                ->exists()
        );

        $this->assertSame(
            'mantenimiento',
            $room->fresh()->cleaningStatus($dayOne->copy()->addDays(2))['code']
        );
    }

    #[Test]
    public function finished_reservations_with_a_stay_in_that_room_do_not_block_maintenance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 10:00:00', 'America/Bogota'));

        $operationalDate = HotelTime::currentOperationalDate();
        $room = $this->createRoom('351');

        $reservationId = DB::table('reservations')->insertGetId([
            'reservation_code' => 'RES-351',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        DB::table('reservation_rooms')->insert([
            'reservation_id' => $reservationId,
            'room_id' => $room->id,
            'check_in_date' => $operationalDate->toDateString(),
            'check_out_date' => $operationalDate->copy()->addDay()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stays')->insert([
            'reservation_id' => $reservationId,
            'room_id' => $room->id,
            'check_in_at' => $operationalDate->copy()->subDay()->setTime(15, 0),
            'check_out_at' => $operationalDate->copy()->setTime(7, 0),
            'status' => 'finished',
        ]);

        $component = new RoomManager();
        $component->date = $operationalDate->copy();
        $component->currentDate = $operationalDate->copy();

        $component->updateCleaningStatus($room->id, 'mantenimiento');

        $this->assertTrue(
            DB::table('room_operational_statuses')
                ->where('room_id', $room->id)
                ->whereDate('operational_date', $operationalDate->toDateString())
                ->where('cleaning_override_status', 'mantenimiento')
                ->exists()
        );
    }

    #[Test]
    public function marking_the_room_clean_removes_the_carried_maintenance_for_the_next_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 10:00:00', 'America/Bogota'));

        $operationalDate = HotelTime::currentOperationalDate();
        $room = $this->createRoom('401');

        $component = new RoomManager();
        $component->date = $operationalDate->copy();
        $component->currentDate = $operationalDate->copy();
        $component->updateCleaningStatus($room->id, 'mantenimiento');
        $component->updateCleaningStatus($room->id, 'limpia');

        $this->assertDatabaseMissing('room_operational_statuses', [
            'room_id' => $room->id,
            'operational_date' => $operationalDate->toDateString(),
        ]);

        $this->assertDatabaseMissing('room_operational_statuses', [
            'room_id' => $room->id,
            'operational_date' => $operationalDate->copy()->addDay()->toDateString(),
        ]);

        $this->assertSame('limpia', $room->fresh()->cleaningStatus($operationalDate)['code']);
        $this->assertSame('limpia', $room->fresh()->cleaningStatus($operationalDate->copy()->addDay())['code']);
    }

    private function createRoom(string $roomNumber): Room
    {
        return Room::query()->create([
            'room_number' => $roomNumber,
            'beds_count' => 1,
            'max_capacity' => 2,
            'base_price_per_night' => 120000,
            'is_active' => true,
            'last_cleaned_at' => now(),
        ]);
    }

    private function recreateTestingSchema(): void
    {
        foreach ([
            'room_operational_statuses',
            'room_maintenance_blocks',
            'room_maintenance_block_statuses',
            'room_quick_reservations',
            'stays',
            'reservation_rooms',
            'reservations',
            'rooms',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('rooms', function (Blueprint $table): void {
            $table->id();
            $table->string('room_number')->unique();
            $table->unsignedBigInteger('room_type_id')->nullable();
            $table->unsignedBigInteger('ventilation_type_id')->nullable();
            $table->unsignedInteger('beds_count')->default(1);
            $table->unsignedInteger('max_capacity')->default(1);
            $table->decimal('base_price_per_night', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('last_cleaned_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reservation_code')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reservation_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('room_id');
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->timestamps();
        });

        Schema::create('stays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('room_id');
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('status')->default('active');
        });

        Schema::create('room_quick_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->date('operational_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'operational_date']);
        });

        Schema::create('room_maintenance_block_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('room_maintenance_blocks', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->dateTime('start_at');
            $table->dateTime('end_at')->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('status_id');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('room_operational_statuses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->date('operational_date');
            $table->text('observation')->nullable();
            $table->string('cleaning_override_status', 40)->nullable();
            $table->date('maintenance_source_date')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'operational_date']);
        });

        Schema::table('room_operational_statuses', function (Blueprint $table): void {
            $table->index(['operational_date', 'cleaning_override_status']);
        });

        Schema::table('room_maintenance_blocks', function (Blueprint $table): void {
            $table->foreign('status_id')->references('id')->on('room_maintenance_block_statuses');
        });

        \DB::table('room_maintenance_block_statuses')->insert([
            'code' => 'active',
            'name' => 'Activo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
