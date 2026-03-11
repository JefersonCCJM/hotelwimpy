<?php

namespace Tests\Feature\Livewire;

use App\Livewire\RoomManager;
use App\Models\Reservation;
use App\Models\ReservationRoom;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StayNight;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoomManagerPaymentStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->recreateTestingSchema();
        $this->seedPaymentStatuses();
    }

    protected function tearDown(): void
    {
        Auth::logout();
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function settled_room_account_does_not_keep_showing_pending_night_after_payment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-09 10:00:00'));

        [$room, $reservation, $stay, $stayNight] = $this->createStayScenario(
            checkInDate: '2026-03-09',
            checkOutDate: '2026-03-10',
            nightPrice: 60000
        );

        Auth::setUser((new User())->forceFill([
            'id' => 1,
            'name' => 'Caja',
            'email' => 'caja@example.com',
        ]));

        $component = new RoomManager();
        $component->date = Carbon::parse('2026-03-09')->startOfDay();
        $component->currentDate = Carbon::parse('2026-03-09')->startOfDay();

        $result = $component->registerPayment($reservation->id, 60000, 'efectivo');

        $this->assertTrue($result);

        $reservation->refresh();
        $stayNight->refresh();

        $this->assertSame(0.0, (float) $reservation->balance_due);
        $this->assertTrue((bool) $stayNight->is_paid);

        $roomForView = $room->fresh();
        $roomForView->is_night_paid = false;

        $html = Blade::render(
            '<x-room-manager.room-payment-info :room="$room" :stay="$stay" :selectedDate="$selectedDate" />',
            [
                'room' => $roomForView,
                'stay' => $stay->fresh()->load('reservation'),
                'selectedDate' => Carbon::parse('2026-03-09')->startOfDay(),
            ]
        );

        $this->assertStringContainsString('NOCHE PAGA', $html);
        $this->assertStringContainsString('Al dia', $html);
        $this->assertStringNotContainsString('NOCHE PENDIENTE', $html);
    }

    /**
     * @return array{0:\App\Models\Room,1:\App\Models\Reservation,2:\App\Models\Stay,3:\App\Models\StayNight}
     */
    private function createStayScenario(string $checkInDate, string $checkOutDate, float $nightPrice): array
    {
        $room = Room::query()->create([
            'room_number' => '501',
            'beds_count' => 1,
            'max_capacity' => 2,
            'base_price_per_night' => $nightPrice,
            'is_active' => true,
            'last_cleaned_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'name' => 'Cliente Cuenta',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        $reservation = Reservation::query()->create([
            'reservation_code' => 'RES-501',
            'client_id' => $customerId,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'total_guests' => 1,
            'adults' => 1,
            'children' => 0,
            'total_amount' => $nightPrice,
            'deposit_amount' => 0,
            'balance_due' => $nightPrice,
            'payment_status_id' => $this->paymentStatusId('pending'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ReservationRoom::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'check_in_date' => $checkInDate,
            'check_out_date' => $checkOutDate,
            'nights' => 1,
            'price_per_night' => $nightPrice,
            'subtotal' => $nightPrice,
        ]);

        $stay = Stay::query()->create([
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'check_in_at' => Carbon::parse($checkInDate . ' 15:00:00'),
            'check_out_at' => null,
            'status' => 'active',
        ]);

        $stayNight = StayNight::query()->create([
            'stay_id' => $stay->id,
            'reservation_id' => $reservation->id,
            'room_id' => $room->id,
            'date' => $checkInDate,
            'price' => $nightPrice,
            'is_paid' => false,
        ]);

        return [$room, $reservation, $stay, $stayNight];
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
            'payments_methods',
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

        Schema::create('payments_methods', function (Blueprint $table): void {
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
