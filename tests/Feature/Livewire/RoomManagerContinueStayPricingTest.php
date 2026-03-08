<?php

namespace Tests\Feature\Livewire;

use App\Livewire\RoomManager;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StayNight;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoomManagerContinueStayPricingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateTestingSchema();
        $this->seedPaymentStatuses();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function continue_stay_adds_a_new_night_with_the_current_room_price_and_updates_totals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-07 10:00:00'));

        [$room, $reservation, $reservationRoom] = $this->createStayScenario(
            roomNumber: '101',
            checkInDate: '2026-03-05',
            checkOutDate: '2026-03-07',
            nightPrices: [60000, 60000],
            paymentsTotal: 120000,
            reservationRoomPricePerNight: 60000
        );

        $component = new RoomManager();
        $component->date = Carbon::parse('2026-03-07')->startOfDay();
        $component->currentDate = Carbon::parse('2026-03-07')->startOfDay();

        $component->continueStay($room->id);

        $reservationRoom->refresh();
        $reservation->refresh();

        $this->assertSame('2026-03-08', Carbon::parse((string) $reservationRoom->check_out_date)->toDateString());
        $this->assertSame(3, (int) $reservationRoom->nights);
        $this->assertSame(60000.0, (float) $reservationRoom->price_per_night);
        $this->assertSame(180000.0, (float) $reservationRoom->subtotal);

        $this->assertSame(180000.0, (float) $reservation->total_amount);
        $this->assertSame(60000.0, (float) $reservation->balance_due);

        $nights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->orderBy('date')
            ->get()
            ->keyBy(fn (StayNight $night): string => $night->date->toDateString());

        $this->assertSame([60000.0, 60000.0, 60000.0], array_map(
            static fn (string $date): float => (float) $nights[$date]->price,
            ['2026-03-05', '2026-03-06', '2026-03-07']
        ));
        $this->assertTrue((bool) $nights['2026-03-05']->is_paid);
        $this->assertTrue((bool) $nights['2026-03-06']->is_paid);
        $this->assertFalse((bool) $nights['2026-03-07']->is_paid);
    }

    #[Test]
    public function continue_stay_uses_the_last_edited_night_price_for_future_nights(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-08 10:00:00'));

        [$room, $reservation, $reservationRoom] = $this->createStayScenario(
            roomNumber: '102',
            checkInDate: '2026-03-05',
            checkOutDate: '2026-03-08',
            nightPrices: [60000, 60000, 50000],
            paymentsTotal: 120000,
            reservationRoomPricePerNight: 56666.67
        );

        $component = new RoomManager();
        $component->date = Carbon::parse('2026-03-08')->startOfDay();
        $component->currentDate = Carbon::parse('2026-03-08')->startOfDay();

        $component->continueStay($room->id);

        $reservationRoom->refresh();
        $reservation->refresh();

        $this->assertSame('2026-03-09', Carbon::parse((string) $reservationRoom->check_out_date)->toDateString());
        $this->assertSame(4, (int) $reservationRoom->nights);
        $this->assertSame(50000.0, round((float) $reservationRoom->price_per_night, 2));
        $this->assertSame(220000.0, (float) $reservationRoom->subtotal);

        $this->assertSame(220000.0, (float) $reservation->total_amount);
        $this->assertSame(100000.0, (float) $reservation->balance_due);

        $nights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->orderBy('date')
            ->get()
            ->keyBy(fn (StayNight $night): string => $night->date->toDateString());

        $this->assertSame([60000.0, 60000.0, 50000.0, 50000.0], array_map(
            static fn (string $date): float => round((float) $nights[$date]->price, 2),
            ['2026-03-05', '2026-03-06', '2026-03-07', '2026-03-08']
        ));
        $this->assertTrue((bool) $nights['2026-03-05']->is_paid);
        $this->assertTrue((bool) $nights['2026-03-06']->is_paid);
        $this->assertFalse((bool) $nights['2026-03-07']->is_paid);
        $this->assertFalse((bool) $nights['2026-03-08']->is_paid);
    }

    #[Test]
    public function repair_command_rebuilds_the_room_total_and_paid_nights_for_a_damaged_reservation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-08 10:00:00'));

        [$room, $reservation, $reservationRoom] = $this->createStayScenario(
            roomNumber: '103',
            checkInDate: '2026-03-05',
            checkOutDate: '2026-03-09',
            nightPrices: [30000, 30000, 30000, 30000],
            paymentsTotal: 120000,
            reservationRoomPricePerNight: 30000,
            markAllNightsPaid: true
        );

        $this->artisan('reservations:repair-room-night-price', [
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'price_per_night' => 60000,
            '--confirm' => true,
        ])->assertExitCode(0);

        $reservationRoom->refresh();
        $reservation->refresh();

        $this->assertSame(4, (int) $reservationRoom->nights);
        $this->assertSame(60000.0, (float) $reservationRoom->price_per_night);
        $this->assertSame(240000.0, (float) $reservationRoom->subtotal);
        $this->assertSame(240000.0, (float) $reservation->total_amount);
        $this->assertSame(120000.0, (float) $reservation->balance_due);

        $nights = StayNight::query()
            ->where('reservation_id', $reservation->id)
            ->orderBy('date')
            ->get()
            ->keyBy(fn (StayNight $night): string => $night->date->toDateString());

        $this->assertSame([60000.0, 60000.0, 60000.0, 60000.0], array_map(
            static fn (string $date): float => (float) $nights[$date]->price,
            ['2026-03-05', '2026-03-06', '2026-03-07', '2026-03-08']
        ));
        $this->assertTrue((bool) $nights['2026-03-05']->is_paid);
        $this->assertTrue((bool) $nights['2026-03-06']->is_paid);
        $this->assertFalse((bool) $nights['2026-03-07']->is_paid);
        $this->assertFalse((bool) $nights['2026-03-08']->is_paid);
    }

    /**
     * @return array{0:\App\Models\Room,1:\App\Models\Reservation,2:\App\Models\ReservationRoom}
     */
    private function createStayScenario(
        string $roomNumber,
        string $checkInDate,
        string $checkOutDate,
        array $nightPrices,
        float $paymentsTotal,
        float $reservationRoomPricePerNight,
        bool $markAllNightsPaid = false
    ): array {
        $room = Room::query()->create([
            'room_number' => $roomNumber,
            'beds_count' => 1,
            'max_capacity' => 2,
            'base_price_per_night' => 60000,
            'is_active' => true,
            'last_cleaned_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Cliente ' . $roomNumber,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $subtotal = round(array_sum($nightPrices), 2);
        $reservation = Reservation::query()->create([
            'reservation_code' => 'RES-' . $roomNumber,
            'client_id' => $customerId,
            'total_guests' => 2,
            'adults' => 2,
            'children' => 0,
            'total_amount' => $subtotal,
            'deposit_amount' => $paymentsTotal,
            'balance_due' => max(0, $subtotal - $paymentsTotal),
            'payment_status_id' => $paymentsTotal >= $subtotal
                ? $this->paymentStatusId('paid')
                : ($paymentsTotal > 0 ? $this->paymentStatusId('partial') : $this->paymentStatusId('pending')),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reservationRoom = ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'nights' => count($nightPrices),
            'price_per_night' => $reservationRoomPricePerNight,
            'subtotal' => $subtotal,
        ]);

        $stay = Stay::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'check_in_at' => Carbon::parse($checkInDate . ' 15:00:00'),
            'check_out_at' => null,
            'status' => 'active',
        ]);

        foreach (array_values($nightPrices) as $index => $price) {
            StayNight::query()->create([
                'stay_id' => $stay->id,
                'reservation_id' => $reservation->id,
                'room_id' => $room->id,
                'date' => Carbon::parse($checkInDate)->addDays($index)->toDateString(),
                'price' => $price,
                'is_paid' => $markAllNightsPaid,
            ]);
        }

        if ($paymentsTotal > 0) {
            DB::table('payments')->insert([
                'reservation_id' => $reservation->id,
                'amount' => $paymentsTotal,
                'payment_method_id' => null,
                'payment_type_id' => null,
                'source_id' => null,
                'reference' => null,
                'bank_name' => null,
                'paid_at' => now(),
                'created_by' => null,
                'notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$room, $reservation, $reservationRoom];
    }

    private function paymentStatusId(string $code): ?int
    {
        return DB::table('payment_statuses')->where('code', $code)->value('id');
    }

    private function seedPaymentStatuses(): void
    {
        DB::table('payment_statuses')->insert([
            ['code' => 'pending', 'name' => 'Pending', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'partial', 'name' => 'Partial', 'created_at' => now(), 'updated_at' => now()],
            ['code' => 'paid', 'name' => 'Paid', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function recreateTestingSchema(): void
    {
        foreach ([
            'reservation_sales',
            'payments',
            'payment_statuses',
            'stay_nights',
            'stays',
            'reservation_rooms',
            'reservations',
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

        Schema::create('reservations', function (Blueprint $table): void {
            $table->id();
            $table->string('reservation_code')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->date('check_in_date')->nullable();
            $table->date('check_out_date')->nullable();
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

        Schema::create('payment_statuses', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedBigInteger('payment_method_id')->nullable();
            $table->unsignedBigInteger('payment_type_id')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('bank_name')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('reservation_sales', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            $table->decimal('total', 12, 2)->default(0);
            $table->boolean('is_paid')->default(false);
            $table->timestamps();
        });
    }
}
