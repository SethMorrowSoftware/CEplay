<?php
/**
 * Party/Event Booking API — birthday parties, group events, private rentals.
 */

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/validator.php';

function handleParties(string $method, array $parts, ?array $input): void {
    Auth::requireAuth();

    $subResource = $parts[0] ?? '';

    // Package management: /api/parties/packages[/{id}]
    if ($subResource === 'packages') {
        $pkgId = $parts[1] ?? '';
        handlePartyPackages($method, $pkgId, $input);
        return;
    }

    // Calendar view: /api/parties/calendar?month=YYYY-MM
    if ($subResource === 'calendar' && $method === 'GET') {
        handlePartyCalendar();
        return;
    }

    // Today's bookings: /api/parties/today
    if ($subResource === 'today' && $method === 'GET') {
        handleTodayBookings();
        return;
    }

    // Booking CRUD
    if ($subResource && $subResource !== 'packages' && $subResource !== 'calendar' && $subResource !== 'today') {
        $id = $subResource;
        $action = $parts[1] ?? '';

        // POST /api/parties/{id}/status
        if ($action === 'status' && $method === 'POST') {
            handleBookingStatusChange($id, $input);
            return;
        }

        switch ($method) {
            case 'GET':
                handleGetBooking($id);
                break;
            case 'PUT':
                handleUpdateBooking($id, $input);
                break;
            case 'DELETE':
                handleDeleteBooking($id);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
        }
        return;
    }

    switch ($method) {
        case 'GET':
            handleListBookings();
            break;
        case 'POST':
            handleCreateBooking($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

// ---- Packages ----

function handlePartyPackages(string $method, string $pkgId, ?array $input): void {
    if ($pkgId) {
        switch ($method) {
            case 'GET':
                $pkg = DB::queryOne('SELECT * FROM party_packages WHERE id = :p0', [(int)$pkgId]);
                if (!$pkg) { http_response_code(404); echo json_encode(['error' => 'Package not found']); return; }
                echo json_encode($pkg);
                break;
            case 'PUT':
                handleUpdatePackage($pkgId, $input);
                break;
            case 'DELETE':
                $existing = DB::queryOne('SELECT id FROM party_packages WHERE id = :p0', [(int)$pkgId]);
                if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Package not found']); return; }
                DB::execute('DELETE FROM party_packages WHERE id = :p0', [(int)$pkgId]);
                echo json_encode(['success' => true]);
                break;
            default:
                http_response_code(405);
                echo json_encode(['error' => 'Method not allowed']);
        }
        return;
    }

    switch ($method) {
        case 'GET':
            $packages = DB::query('SELECT * FROM party_packages ORDER BY price_cents ASC');
            echo json_encode(['packages' => $packages]);
            break;
        case 'POST':
            handleCreatePackage($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
}

function handleCreatePackage(?array $input): void {
    if (!$input) { http_response_code(400); echo json_encode(['error' => 'Request body required']); return; }

    $name = Validator::requireString($input, 'name');
    $description = Validator::optionalString($input, 'description', 2000);
    $duration = Validator::optionalInt($input, 'duration_minutes', 30, 480) ?? 120;
    $maxGuests = Validator::optionalInt($input, 'max_guests', 1, 200) ?? 20;
    $price = Validator::optionalInt($input, 'price_cents', 0, 9999999) ?? 0;
    $food = isset($input['includes_food']) ? ($input['includes_food'] ? 1 : 0) : 0;
    $drinks = isset($input['includes_drinks']) ? ($input['includes_drinks'] ? 1 : 0) : 0;
    $credits = Validator::optionalInt($input, 'game_credits', 0, 99999) ?? 0;
    $rides = Validator::optionalInt($input, 'ride_passes', 0, 999) ?? 0;

    DB::execute(
        'INSERT INTO party_packages (name, description, duration_minutes, max_guests, price_cents, includes_food, includes_drinks, game_credits, ride_passes)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8)',
        [$name, $description, $duration, $maxGuests, $price, $food, $drinks, $credits, $rides]
    );
    $id = DB::lastInsertId();
    $pkg = DB::queryOne('SELECT * FROM party_packages WHERE id = :p0', [$id]);
    http_response_code(201);
    echo json_encode($pkg);
}

function handleUpdatePackage(string $pkgId, ?array $input): void {
    if (!$input) { http_response_code(400); echo json_encode(['error' => 'Request body required']); return; }

    $existing = DB::queryOne('SELECT * FROM party_packages WHERE id = :p0', [(int)$pkgId]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Package not found']); return; }

    $name = isset($input['name']) ? Validator::requireString($input, 'name') : $existing['name'];
    $description = isset($input['description']) ? Validator::optionalString($input, 'description', 2000) : $existing['description'];
    $duration = isset($input['duration_minutes']) ? (Validator::optionalInt($input, 'duration_minutes', 30, 480) ?? 120) : $existing['duration_minutes'];
    $maxGuests = isset($input['max_guests']) ? (Validator::optionalInt($input, 'max_guests', 1, 200) ?? 20) : $existing['max_guests'];
    $price = isset($input['price_cents']) ? (Validator::optionalInt($input, 'price_cents', 0, 9999999) ?? 0) : $existing['price_cents'];
    $food = isset($input['includes_food']) ? ($input['includes_food'] ? 1 : 0) : $existing['includes_food'];
    $drinks = isset($input['includes_drinks']) ? ($input['includes_drinks'] ? 1 : 0) : $existing['includes_drinks'];
    $credits = isset($input['game_credits']) ? (Validator::optionalInt($input, 'game_credits', 0, 99999) ?? 0) : $existing['game_credits'];
    $rides = isset($input['ride_passes']) ? (Validator::optionalInt($input, 'ride_passes', 0, 999) ?? 0) : $existing['ride_passes'];
    $active = isset($input['is_active']) ? ($input['is_active'] ? 1 : 0) : $existing['is_active'];

    DB::execute(
        'UPDATE party_packages SET name = :p0, description = :p1, duration_minutes = :p2, max_guests = :p3,
         price_cents = :p4, includes_food = :p5, includes_drinks = :p6, game_credits = :p7,
         ride_passes = :p8, is_active = :p9, updated_at = datetime(\'now\') WHERE id = :p10',
        [$name, $description, $duration, $maxGuests, $price, $food, $drinks, $credits, $rides, $active, (int)$pkgId]
    );

    $pkg = DB::queryOne('SELECT * FROM party_packages WHERE id = :p0', [(int)$pkgId]);
    echo json_encode($pkg);
}

// ---- Bookings ----

function handleListBookings(): void {
    $status = $_GET['status'] ?? '';
    $date = $_GET['date'] ?? '';
    $validStatuses = ['pending', 'confirmed', 'cancelled', 'completed'];

    if ($status !== '' && !in_array($status, $validStatuses, true)) {
        throw new RuntimeException('Invalid status filter. Allowed: ' . implode(', ', $validStatuses));
    }
    if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new RuntimeException('Invalid date filter. Use YYYY-MM-DD.');
    }

    $sql = 'SELECT pb.*, pp.name as package_name FROM party_bookings pb LEFT JOIN party_packages pp ON pb.package_id = pp.id';
    $params = [];
    $conditions = [];
    $idx = 0;

    if ($status) {
        $conditions[] = "pb.status = :p$idx";
        $params[$idx++] = $status;
    }
    if ($date) {
        $conditions[] = "pb.party_date = :p$idx";
        $params[$idx++] = $date;
    }
    if ($conditions) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY pb.party_date ASC, pb.start_time ASC';

    $bookings = DB::query($sql, $params);
    echo json_encode(['bookings' => $bookings]);
}

function handleGetBooking(string $id): void {
    $booking = DB::queryOne(
        'SELECT pb.*, pp.name as package_name, pp.description as package_description,
         pp.duration_minutes as package_duration, pp.game_credits as package_credits,
         pp.ride_passes as package_rides
         FROM party_bookings pb LEFT JOIN party_packages pp ON pb.package_id = pp.id WHERE pb.id = :p0',
        [(int)$id]
    );
    if (!$booking) {
        http_response_code(404);
        echo json_encode(['error' => 'Booking not found']);
        return;
    }
    echo json_encode($booking);
}

function handleCreateBooking(?array $input): void {
    if (!$input) { http_response_code(400); echo json_encode(['error' => 'Request body required']); return; }

    $user = Auth::check();
    $packageId = Validator::optionalInt($input, 'package_id', 1);
    $guestName = Validator::requireString($input, 'guest_name');
    $guestPhone = Validator::optionalString($input, 'guest_phone', 20);
    $guestEmail = Validator::optionalString($input, 'guest_email');
    $partyDate = Validator::requireDate($input, 'party_date');
    $startTime = Validator::requireTime($input, 'start_time');
    $endTime = Validator::requireTime($input, 'end_time');
    $guestCount = Validator::optionalInt($input, 'guest_count', 1, 500) ?? 10;
    $specialRequests = Validator::optionalString($input, 'special_requests', 2000);
    $status = isset($input['status']) ? Validator::requireEnum($input, 'status', ['pending', 'confirmed', 'cancelled', 'completed']) : 'confirmed';
    $depositPaid = isset($input['deposit_paid']) ? ($input['deposit_paid'] ? 1 : 0) : 0;
    $totalPrice = Validator::optionalInt($input, 'total_price_cents', 0, 9999999) ?? 0;
    $area = Validator::optionalString($input, 'assigned_area');

    validatePartyTimeWindow($startTime, $endTime);

    DB::execute(
        'INSERT INTO party_bookings (package_id, guest_name, guest_phone, guest_email, party_date, start_time, end_time, guest_count, special_requests, status, deposit_paid, total_price_cents, assigned_area, created_by)
         VALUES (:p0, :p1, :p2, :p3, :p4, :p5, :p6, :p7, :p8, :p9, :p10, :p11, :p12, :p13)',
        [$packageId, $guestName, $guestPhone, $guestEmail, $partyDate, $startTime, $endTime, $guestCount, $specialRequests, $status, $depositPaid, $totalPrice, $area, $user['id'] ?? null]
    );

    $id = DB::lastInsertId();
    $booking = DB::queryOne('SELECT pb.*, pp.name as package_name FROM party_bookings pb LEFT JOIN party_packages pp ON pb.package_id = pp.id WHERE pb.id = :p0', [$id]);
    http_response_code(201);
    echo json_encode($booking);
}

function handleUpdateBooking(string $id, ?array $input): void {
    if (!$input) { http_response_code(400); echo json_encode(['error' => 'Request body required']); return; }

    $existing = DB::queryOne('SELECT * FROM party_bookings WHERE id = :p0', [(int)$id]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Booking not found']); return; }

    $packageId = isset($input['package_id']) ? Validator::optionalInt($input, 'package_id', 1) : $existing['package_id'];
    $guestName = isset($input['guest_name']) ? Validator::requireString($input, 'guest_name') : $existing['guest_name'];
    $guestPhone = isset($input['guest_phone']) ? Validator::optionalString($input, 'guest_phone', 20) : $existing['guest_phone'];
    $guestEmail = isset($input['guest_email']) ? Validator::optionalString($input, 'guest_email') : $existing['guest_email'];
    $partyDate = isset($input['party_date']) ? Validator::requireDate($input, 'party_date') : $existing['party_date'];
    $startTime = isset($input['start_time']) ? Validator::requireTime($input, 'start_time') : $existing['start_time'];
    $endTime = isset($input['end_time']) ? Validator::requireTime($input, 'end_time') : $existing['end_time'];
    $guestCount = isset($input['guest_count']) ? (Validator::optionalInt($input, 'guest_count', 1, 500) ?? 10) : $existing['guest_count'];
    $specialRequests = isset($input['special_requests']) ? Validator::optionalString($input, 'special_requests', 2000) : $existing['special_requests'];
    $status = isset($input['status']) ? Validator::requireEnum($input, 'status', ['pending', 'confirmed', 'cancelled', 'completed']) : $existing['status'];
    $depositPaid = isset($input['deposit_paid']) ? ($input['deposit_paid'] ? 1 : 0) : $existing['deposit_paid'];
    $totalPrice = isset($input['total_price_cents']) ? (Validator::optionalInt($input, 'total_price_cents', 0, 9999999) ?? 0) : $existing['total_price_cents'];
    $area = isset($input['assigned_area']) ? Validator::optionalString($input, 'assigned_area') : $existing['assigned_area'];
    validatePartyTimeWindow($startTime, $endTime);

    DB::execute(
        'UPDATE party_bookings SET package_id = :p0, guest_name = :p1, guest_phone = :p2, guest_email = :p3,
         party_date = :p4, start_time = :p5, end_time = :p6, guest_count = :p7, special_requests = :p8,
         status = :p9, deposit_paid = :p10, total_price_cents = :p11, assigned_area = :p12, updated_at = datetime(\'now\')
         WHERE id = :p13',
        [$packageId, $guestName, $guestPhone, $guestEmail, $partyDate, $startTime, $endTime, $guestCount, $specialRequests, $status, $depositPaid, $totalPrice, $area, (int)$id]
    );

    $booking = DB::queryOne('SELECT pb.*, pp.name as package_name FROM party_bookings pb LEFT JOIN party_packages pp ON pb.package_id = pp.id WHERE pb.id = :p0', [(int)$id]);
    echo json_encode($booking);
}

function handleDeleteBooking(string $id): void {
    $existing = DB::queryOne('SELECT * FROM party_bookings WHERE id = :p0', [(int)$id]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Booking not found']); return; }
    DB::execute('DELETE FROM party_bookings WHERE id = :p0', [(int)$id]);
    echo json_encode(['success' => true]);
}

function handleBookingStatusChange(string $id, ?array $input): void {
    if (!$input) { http_response_code(400); echo json_encode(['error' => 'Request body required']); return; }

    $existing = DB::queryOne('SELECT * FROM party_bookings WHERE id = :p0', [(int)$id]);
    if (!$existing) { http_response_code(404); echo json_encode(['error' => 'Booking not found']); return; }

    $status = Validator::requireEnum($input, 'status', ['pending', 'confirmed', 'cancelled', 'completed']);
    DB::execute('UPDATE party_bookings SET status = :p0, updated_at = datetime(\'now\') WHERE id = :p1', [$status, (int)$id]);

    $booking = DB::queryOne('SELECT pb.*, pp.name as package_name FROM party_bookings pb LEFT JOIN party_packages pp ON pb.package_id = pp.id WHERE pb.id = :p0', [(int)$id]);
    echo json_encode($booking);
}

function handleTodayBookings(): void {
    // Use venue timezone for "today" instead of SQLite's UTC date('now')
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $today = (new DateTime('now', new DateTimeZone($tz)))->format('Y-m-d');

    $bookings = DB::query(
        'SELECT pb.*, pp.name as package_name FROM party_bookings pb
         LEFT JOIN party_packages pp ON pb.package_id = pp.id
         WHERE pb.party_date = :p0 AND pb.status != \'cancelled\'
         ORDER BY pb.start_time ASC',
        [$today]
    );
    echo json_encode(['bookings' => $bookings]);
}

function handlePartyCalendar(): void {
    $tz = DB::getConfig('timezone') ?? DEFAULT_TIMEZONE;
    $month = $_GET['month'] ?? (new DateTime('now', new DateTimeZone($tz)))->format('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid month format. Use YYYY-MM.']);
        return;
    }

    [$year, $monthNumber] = array_map('intval', explode('-', $month, 2));
    if ($monthNumber < 1 || $monthNumber > 12) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid month value. Month must be between 01 and 12.']);
        return;
    }

    $startDate = sprintf('%04d-%02d-01', $year, $monthNumber);
    $endDate = date('Y-m-t', strtotime($startDate));

    $bookings = DB::query(
        'SELECT pb.*, pp.name as package_name FROM party_bookings pb
         LEFT JOIN party_packages pp ON pb.package_id = pp.id
         WHERE pb.party_date >= :p0 AND pb.party_date <= :p1 AND pb.status != \'cancelled\'
         ORDER BY pb.party_date ASC, pb.start_time ASC',
        [$startDate, $endDate]
    );

    // Group by date
    $calendar = [];
    foreach ($bookings as $b) {
        $date = $b['party_date'];
        if (!isset($calendar[$date])) {
            $calendar[$date] = [];
        }
        $calendar[$date][] = $b;
    }

    echo json_encode(['month' => $month, 'calendar' => $calendar, 'bookings' => $bookings]);
}

function validatePartyTimeWindow(string $startTime, string $endTime): void {
    if ($startTime >= $endTime) {
        throw new RuntimeException('End time must be after start time.');
    }
}
