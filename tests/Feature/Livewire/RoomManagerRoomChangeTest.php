<?php

namespace Tests\Feature\Livewire;

use App\Livewire\RoomManager;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\RoomQuickReservation;
use App\Models\Stay;
use App\Models\StayNight;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

class RoomManagerRoomChangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateTestingSchema();
        $this->seedReservationStatuses();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function finished_stays_do_not_reappear_as_pending_checkin_for_the_same_room(): void
    {
        $today = Carbon::parse('2026-03-07 15:00:00');
        Carbon::setTestNow($today);

        $room = $this->createRoom('101');
        $reservation = $this->createReservation('RES-101', 'pending');

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'check_in_date' => $today->toDateString(),
            'check_out_date' => $today->copy()->addDay()->toDateString(),
            'nights' => 1,
            'price_per_night' => 120000,
            'subtotal' => 120000,
        ]);

        Stay::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'check_in_at' => $today->copy()->subHours(5),
            'check_out_at' => $today->copy()->subHour(),
            'status' => 'finished',
        ]);

        $pendingReservation = $this->invokePendingReservationHelper($room, $today);

        $this->assertNull($pendingReservation);
    }

    #[Test]
    public function pending_reservation_room_change_only_moves_the_selected_room_and_keeps_checkin_pending(): void
    {
        $today = Carbon::parse('2026-03-07 11:00:00');
        Carbon::setTestNow($today);

        $sourceRoom = $this->createRoom('201');
        $otherReservedRoom = $this->createRoom('202');
        $targetRoom = $this->createRoom('203');

        $reservation = $this->createReservation('RES-201', 'pending');

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $sourceRoom->id,
            'check_in_date' => $today->toDateString(),
            'check_out_date' => $today->copy()->addDay()->toDateString(),
            'nights' => 1,
            'price_per_night' => 150000,
            'subtotal' => 150000,
        ]);

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $otherReservedRoom->id,
            'check_in_date' => $today->toDateString(),
            'check_out_date' => $today->copy()->addDay()->toDateString(),
            'nights' => 1,
            'price_per_night' => 150000,
            'subtotal' => 150000,
        ]);

        RoomQuickReservation::query()->create([
            'room_id' => $sourceRoom->id,
            'operational_date' => $today->toDateString(),
        ]);

        $component = new RoomManager();
        $component->date = $today->copy();
        $component->currentDate = $today->copy();
        $component->changeRoomData = [
            'room_id' => $sourceRoom->id,
            'reservation_id' => $reservation->id,
            'change_mode' => 'pending_reservation',
        ];

        $component->submitChangeRoom($targetRoom->id);

        $this->assertDatabaseHas('reservation_rooms', [
            'reservation_id' => $reservation->id,
            'room_id' => $targetRoom->id,
        ]);

        $this->assertDatabaseHas('reservation_rooms', [
            'reservation_id' => $reservation->id,
            'room_id' => $otherReservedRoom->id,
        ]);

        $this->assertDatabaseMissing('reservation_rooms', [
            'reservation_id' => $reservation->id,
            'room_id' => $sourceRoom->id,
        ]);

        $this->assertTrue(
            RoomQuickReservation::query()
                ->where('room_id', $targetRoom->id)
                ->whereDate('operational_date', $today->toDateString())
                ->exists()
        );

        $this->assertFalse(
            RoomQuickReservation::query()
                ->where('room_id', $sourceRoom->id)
                ->whereDate('operational_date', $today->toDateString())
                ->exists()
        );

        $this->assertSame(0, Stay::query()->where('reservation_id', $reservation->id)->count());
        $this->assertNotNull($sourceRoom->fresh()->last_cleaned_at);
        $this->assertSame('free_clean', $targetRoom->fresh()->getOperationalStatus($today));
        $this->assertNull($this->invokePendingReservationHelper($sourceRoom->fresh(), $today));
        $this->assertSame($reservation->id, $this->invokePendingReservationHelper($targetRoom->fresh(), $today)?->id);
    }

    #[Test]
    public function active_room_change_only_moves_the_selected_room_and_occupies_the_target_room(): void
    {
        $today = Carbon::parse('2026-03-07 16:00:00');
        Carbon::setTestNow($today);

        $sourceRoom = $this->createRoom('301');
        $otherOccupiedRoom = $this->createRoom('302');
        $targetRoom = $this->createRoom('303');

        $reservation = $this->createReservation('RES-301', 'checked_in');

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $sourceRoom->id,
            'check_in_date' => $today->toDateString(),
            'check_out_date' => $today->copy()->addDays(2)->toDateString(),
            'nights' => 2,
            'price_per_night' => 175000,
            'subtotal' => 350000,
        ]);

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $otherOccupiedRoom->id,
            'check_in_date' => $today->toDateString(),
            'check_out_date' => $today->copy()->addDays(2)->toDateString(),
            'nights' => 2,
            'price_per_night' => 175000,
            'subtotal' => 350000,
        ]);

        $sourceStay = Stay::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $sourceRoom->id,
            'check_in_at' => $today->copy()->subHours(6),
            'check_out_at' => null,
            'status' => 'active',
        ]);

        $otherOccupiedStay = Stay::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $otherOccupiedRoom->id,
            'check_in_at' => $today->copy()->subHours(6),
            'check_out_at' => null,
            'status' => 'active',
        ]);

        StayNight::query()->create([
            'stay_id' => $sourceStay->id,
            'reservation_id' => $reservation->id,
            'room_id' => $sourceRoom->id,
            'date' => $today->toDateString(),
            'price' => 175000,
            'is_paid' => false,
        ]);

        StayNight::query()->create([
            'stay_id' => $sourceStay->id,
            'reservation_id' => $reservation->id,
            'room_id' => $sourceRoom->id,
            'date' => $today->copy()->addDay()->toDateString(),
            'price' => 175000,
            'is_paid' => false,
        ]);

        StayNight::query()->create([
            'stay_id' => $otherOccupiedStay->id,
            'reservation_id' => $reservation->id,
            'room_id' => $otherOccupiedRoom->id,
            'date' => $today->toDateString(),
            'price' => 175000,
            'is_paid' => false,
        ]);

        $component = new RoomManager();
        $component->date = $today->copy();
        $component->currentDate = $today->copy();
        $component->changeRoomData = [
            'room_id' => $sourceRoom->id,
            'reservation_id' => $reservation->id,
            'change_mode' => 'active_stay',
        ];

        $component->submitChangeRoom($targetRoom->id);

        $this->assertDatabaseHas('stays', [
            'id' => $sourceStay->id,
            'room_id' => $sourceRoom->id,
            'status' => 'finished',
        ]);

        $this->assertSame(1, Stay::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $targetRoom->id)
            ->whereNull('check_out_at')
            ->count());

        $this->assertSame(1, Stay::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $otherOccupiedRoom->id)
            ->whereNull('check_out_at')
            ->count());

        $this->assertDatabaseHas('reservation_rooms', [
            'reservation_id' => $reservation->id,
            'room_id' => $targetRoom->id,
        ]);

        $this->assertDatabaseHas('reservation_rooms', [
            'reservation_id' => $reservation->id,
            'room_id' => $otherOccupiedRoom->id,
        ]);

        $this->assertDatabaseMissing('reservation_rooms', [
            'reservation_id' => $reservation->id,
            'room_id' => $sourceRoom->id,
        ]);

        $this->assertSame(2, StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $targetRoom->id)
            ->count());

        $this->assertSame(1, StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $otherOccupiedRoom->id)
            ->count());

        $this->assertSame(0, StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->where('room_id', $sourceRoom->id)
            ->count());

        $this->assertNull($sourceRoom->fresh()->last_cleaned_at);
        $this->assertSame('occupied', $targetRoom->fresh()->getOperationalStatus($today));
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

    private function createReservation(string $code, string $statusCode): Reservation
    {
        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Cliente ' . $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $statusId = (int) DB::table('reservation_statuses')
            ->where('code', $statusCode)
            ->value('id');

        return Reservation::query()->create([
            'reservation_code' => $code,
            'client_id' => $customerId,
            'status_id' => $statusId ?: null,
            'total_guests' => 2,
            'adults' => 2,
            'children' => 0,
            'total_amount' => 350000,
            'deposit_amount' => 0,
            'balance_due' => 350000,
            'payment_status_id' => null,
            'source_id' => null,
            'created_by' => null,
            'notes' => null,
        ]);
    }

    private function invokePendingReservationHelper(Room $room, Carbon $date): ?Reservation
    {
        $component = new RoomManager();
        $component->date = $date->copy();
        $component->currentDate = $date->copy();

        $method = new ReflectionMethod(RoomManager::class, 'getPendingCheckInReservationForRoom');
        $method->setAccessible(true);

        return $method->invoke($component, $room, $date->copy()->startOfDay());
    }

    private function recreateTestingSchema(): void
    {
        foreach ([
            'room_quick_reservations',
            'stay_nights',
            'stays',
            'reservation_rooms',
            'reservations',
            'reservation_statuses',
            'customers',
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

        Schema::create('customers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reservation_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reservation_code')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->unsignedInteger('total_guests')->nullable();
            $table->unsignedInteger('adults')->nullable();
            $table->unsignedInteger('children')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('deposit_amount', 12, 2)->default(0);
            $table->decimal('balance_due', 12, 2)->default(0);
            $table->unsignedBigInteger('payment_status_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reservation_rooms', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('room_id');
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->unsignedInteger('nights')->nullable();
            $table->decimal('price_per_night', 12, 2)->nullable();
            $table->decimal('subtotal', 12, 2)->nullable();
            $table->timestamps();
            $table->unique(['reservation_id', 'room_id']);
        });

        Schema::create('stays', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('room_id');
            $table->dateTime('check_in_at')->nullable();
            $table->dateTime('check_out_at')->nullable();
            $table->string('status')->default('active');
        });

        Schema::create('stay_nights', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stay_id');
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('room_id');
            $table->date('date');
            $table->decimal('price', 12, 2);
            $table->boolean('is_paid')->default(false);
            $table->timestamps();
            $table->unique(['stay_id', 'date']);
        });

        Schema::create('room_quick_reservations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('room_id');
            $table->date('operational_date');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['room_id', 'operational_date']);
        });
    }

    private function seedReservationStatuses(): void
    {
        DB::table('reservation_statuses')->insert([
            ['code' => 'pending', 'name' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'checked_in', 'name' => 'Checked In', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'checked_out', 'name' => 'Checked Out', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'cancelled', 'name' => 'Cancelled', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
