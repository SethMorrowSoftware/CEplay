/**
 * Mock data fixture for the Castle Fun Center demo.
 *
 * All timestamps are computed relative to the moment the page loaded so
 * "Active Now" overrides, recent transactions, and log entries always
 * look fresh. The data is mutable: pause/unpause buttons, group edits,
 * schedule edits, etc. update the in-memory store and persist for the
 * duration of the page session.
 */
(function() {
    var now = new Date();

    function pad2(n) { return String(n).padStart(2, '0'); }

    /** Produce an ISO-8601 string in UTC (the format the real backend uses). */
    function isoUtc(d) {
        return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate())
            + 'T' + pad2(d.getUTCHours()) + ':' + pad2(d.getUTCMinutes()) + ':' + pad2(d.getUTCSeconds()) + 'Z';
    }

    /** Produce a "YYYY-MM-DD HH:MM:SS" UTC string (alternate backend format). */
    function sqlUtc(d) {
        return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate())
            + ' ' + pad2(d.getUTCHours()) + ':' + pad2(d.getUTCMinutes()) + ':' + pad2(d.getUTCSeconds());
    }

    function offsetMin(min) { return new Date(now.getTime() + min * 60 * 1000); }
    function offsetHour(h) { return new Date(now.getTime() + h * 3600 * 1000); }
    function offsetDay(d) { return new Date(now.getTime() + d * 86400 * 1000); }

    /** "YYYY-MM-DD" in LOCAL time — matches the Performance page's day buckets. */
    function localDate(d) {
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    // --- Categories ---
    var categories = [
        { id: 1, name: 'Redemption Games',     numberOfGames: 32 },
        { id: 2, name: 'Video Games',          numberOfGames: 24 },
        { id: 3, name: 'Pinball',              numberOfGames: 12 },
        { id: 4, name: 'Sports & Skill',       numberOfGames: 18 },
        { id: 5, name: 'Kids Zone',            numberOfGames: 14 },
        { id: 6, name: 'Virtual Reality',      numberOfGames: 8 },
        { id: 7, name: 'Driving & Racing',     numberOfGames: 10 },
        { id: 8, name: 'Music & Rhythm',       numberOfGames: 6 },
        { id: 9, name: 'Light Gun',            numberOfGames: 6 }
    ];

    // --- Games (130 games across 9 categories) ---
    // The main floor at Castle Fun Center holds well over 100 individual
    // arcade machines, so the demo dataset is sized to match. Names are
    // a mix of recognizable arcade titles plus numbered duplicates ("Bay 3",
    // "Lane 7") to mimic what a real venue's directory looks like.
    function makeGame(id, name, status, catId, catName) {
        return {
            game_id: 'GAME-' + String(id).padStart(3, '0'),
            game_name: name,
            operation_status: status,
            categories: [{ id: catId, name: catName }]
        };
    }
    function statusFor(i, oosEvery, pausedEvery) {
        // Spread some out-of-service and paused machines across the floor
        // so filters and badges are visibly mixed in the demo.
        if (oosEvery && i % oosEvery === 0) return 'outOfService';
        if (pausedEvery && i % pausedEvery === 0) return 'paused';
        return 'enabled';
    }

    var games = [];
    var nextGameNum = 1;
    function pushGame(name, status, catId, catName) {
        games.push(makeGame(nextGameNum, name, status, catId, catName));
        nextGameNum++;
    }

    // Redemption Games (32) — the largest group at most family arcades
    var redemptionNames = [
        'Skee-Ball Classic Lane 1', 'Skee-Ball Classic Lane 2', 'Skee-Ball Classic Lane 3',
        'Skee-Ball Tournament Lane 1', 'Skee-Ball Tournament Lane 2',
        'Whac-A-Mole', 'Big Bass Wheel Pro', 'Connect 4 Hoops',
        'Down the Clown', 'Cyclone', 'Monster Drop XL', 'Stacker Jumbo',
        'Smokin’ Token', 'Spin’n’Win', 'Wheel Deal',
        'Ticket Time', 'Hyper Pitch', 'Plinko Royale', 'Frog Frenzy',
        'Roll-A-Ball Lane 1', 'Roll-A-Ball Lane 2', 'Roll-A-Ball Lane 3',
        'Pop-A-Shot Mini', 'Bowler Roller', 'Ticket Tornado',
        'Hammer & Bell', 'Lucky Stripes', 'Chuck’s Choice',
        'Treasure Chest', 'Power Drop', 'Quick-Pitch', 'Big Score Hoops'
    ];
    redemptionNames.forEach(function(n, i) { pushGame(n, statusFor(i, 16, 7), 1, 'Redemption Games'); });

    // Video Games (24)
    var videoNames = [
        'Pac-Man Cabinet', 'Ms. Pac-Man', 'Galaga', 'Donkey Kong', 'Centipede',
        'Mortal Kombat 11', 'Street Fighter VI Cab', 'Tekken 8 Cab',
        'Crossy Road Arcade', 'Killer Queen Black', 'Tetris Effect Connected',
        'Asteroids Deluxe', 'Frogger Classic', 'Q*bert',
        'Marvel vs Capcom', 'King of Fighters XV', 'Puyo Puyo Champions',
        'Bubble Bobble', 'Galaxian', 'Defender', 'Rampage Classic',
        'Joust', 'Super Mario Bros. Cab', 'Tron Cabinet'
    ];
    videoNames.forEach(function(n, i) { pushGame(n, statusFor(i, 18, 9), 2, 'Video Games'); });

    // Pinball (12)
    var pinballNames = [
        'Jurassic Park Premium', 'AC/DC Premium', 'Star Trek LE',
        'Stranger Things Pro', 'Godzilla Pro', 'James Bond 007 Pro',
        'Foo Fighters Pro', 'The Mandalorian Pro', 'Avengers Infinity Quest',
        'Iron Maiden Premium', 'Deadpool Pro', 'Black Knight: Sword of Rage'
    ];
    pinballNames.forEach(function(n, i) { pushGame(n, statusFor(i, 11, 5), 3, 'Pinball'); });

    // Sports & Skill (18)
    var sportsNames = [
        'Air Hockey Table 1', 'Air Hockey Table 2', 'Air Hockey Table 3',
        'Mini Bowling Lane 1', 'Mini Bowling Lane 2', 'Mini Bowling Lane 3',
        'NBA Game Time', 'Pop-A-Shot Pro', 'Slam-A-Winner Hoops',
        'Cobra Punch Bag', 'Boxing Champ', 'Speed Pitch',
        'Foosball Table A', 'Foosball Table B', 'Pop-Up Putt-Putt',
        'Skeeball Pro Bay 1', 'Skeeball Pro Bay 2', 'Slap Shot Hockey'
    ];
    sportsNames.forEach(function(n, i) { pushGame(n, statusFor(i, 17, 6), 4, 'Sports & Skill'); });

    // Kids Zone (14)
    var kidsNames = [
        'Kiddie Carousel', 'Toddler Train', 'Tea Cup Spinner',
        'Plush Crane Bay 1', 'Plush Crane Bay 2', 'Plush Crane Bay 3',
        'Kids Coaster Mini', 'Bouncy Pony', 'Sing-A-Long Stage',
        'Lucky Duck Pond', 'Bubble Blower', 'Storybook Train',
        'Color Splash Wall', 'Animal Safari Ride'
    ];
    kidsNames.forEach(function(n, i) { pushGame(n, statusFor(i, 14, 8), 5, 'Kids Zone'); });

    // Virtual Reality (8)
    var vrNames = [
        'VR Battle Pod — Bay 1', 'VR Battle Pod — Bay 2',
        'VR Battle Pod — Bay 3', 'VR Battle Pod — Bay 4',
        'VR Coaster Sim', 'VR Sky Diver', 'VR Zombie Co-op', 'VR Mech Combat'
    ];
    vrNames.forEach(function(n, i) { pushGame(n, statusFor(i, 0, 7), 6, 'Virtual Reality'); });

    // Driving & Racing (10)
    var drivingNames = [
        'Mario Kart Arcade GP DX Cab 1', 'Mario Kart Arcade GP DX Cab 2',
        'Daytona USA Twin', 'Cruis’n Blast 1', 'Cruis’n Blast 2',
        'Fast & Furious Super Cars', 'Sega Rally', 'Initial D 8',
        'OutRun 2 Special Tours', 'Hot Wheels King of the Road'
    ];
    drivingNames.forEach(function(n, i) { pushGame(n, statusFor(i, 10, 4), 7, 'Driving & Racing'); });

    // Music & Rhythm (6)
    var musicNames = [
        'Pump It Up Phoenix', 'Dance Dance Revolution A3', 'StepManiaX Bay 1',
        'Sound Voltex VI', 'Beatmania IIDX', 'Maimai DX'
    ];
    musicNames.forEach(function(n, i) { pushGame(n, statusFor(i, 0, 5), 8, 'Music & Rhythm'); });

    // Light Gun (6)
    var lightGunNames = [
        'Time Crisis 5', 'House of the Dead Scarlet Dawn', 'Halo: Fireteam Raven',
        'Aliens Armageddon', 'Walking Dead Light Gun', 'Terminator Salvation'
    ];
    lightGunNames.forEach(function(n, i) { pushGame(n, statusFor(i, 6, 3), 9, 'Light Gun'); });

    // Per-game RPC actions exposed when you open the detail modal.
    var gameDetails = {};
    games.forEach(function(g) {
        gameDetails[g.game_id] = {
            id: g.game_id,
            name: g.game_name,
            operationStatus: g.operation_status,
            virtualPlayEnabled: g.categories[0].name === 'Virtual Reality',
            categories: g.categories.map(function(c) { return c.name; }),
            supportedActions: [
                { id: 'reboot', name: 'Reboot', requireManager: true },
                { id: 'reset-tickets', name: 'Reset ticket counter', requireManager: false }
            ]
        };
    });

    // --- Kiosks ---
    // 28 kiosks placed around the venue — front desk, arcade refill stations,
    // redemption counters, birthday wing, mobile kiosks. A few have unknown
    // status (legacy / pre-API hardware) so the kiosk page can demonstrate
    // its "cannot pause-control" rendering.
    function makeKiosk(id, name, status, cats, withActions) {
        return {
            id: 'KIOSK-' + String(id).padStart(2, '0'),
            name: name,
            operationStatus: status,
            categories: cats,
            supportedActions: withActions ? [{ id: 'reboot', name: 'Reboot', requireManager: true }] : []
        };
    }
    var kiosks = [
        makeKiosk(1,  'Lobby Card Kiosk',                'enabled',     ['Front Desk'], true),
        makeKiosk(2,  'Lobby Self-Service Kiosk',        'enabled',     ['Front Desk'], true),
        makeKiosk(3,  'Arcade Floor Refill 1',           'enabled',     ['Arcade'],    true),
        makeKiosk(4,  'Arcade Floor Refill 2',           'enabled',     ['Arcade'],    true),
        makeKiosk(5,  'Arcade Floor Refill 3',           'paused',      ['Arcade'],    true),
        makeKiosk(6,  'Redemption Counter A',            'enabled',     ['Redemption'], false),
        makeKiosk(7,  'Redemption Counter B',            'paused',      ['Redemption'], false),
        makeKiosk(8,  'Redemption Counter C',            'enabled',     ['Redemption'], false),
        makeKiosk(9,  'Birthday Wing Kiosk 1',           'outOfService', ['Events'],   true),
        makeKiosk(10, 'Birthday Wing Kiosk 2',           'enabled',     ['Events'],   true),
        makeKiosk(11, 'Mobile Refill Cart 1',            'enabled',     ['Mobile'],   true),
        makeKiosk(12, 'Mobile Refill Cart 2',            'enabled',     ['Mobile'],   true),
        makeKiosk(13, 'Mobile Refill Cart 3',            'paused',      ['Mobile'],   true),
        makeKiosk(14, 'Café Tabletop Kiosk 1',           'enabled',     ['Café'],     true),
        makeKiosk(15, 'Café Tabletop Kiosk 2',           'enabled',     ['Café'],     true),
        makeKiosk(16, 'Café Tabletop Kiosk 3',           'enabled',     ['Café'],     true),
        makeKiosk(17, 'Café Tabletop Kiosk 4',           'enabled',     ['Café'],     true),
        makeKiosk(18, 'VR Lobby Kiosk',                  'enabled',     ['VR Wing'],  true),
        makeKiosk(19, 'Outdoor Patio Kiosk',             'paused',      ['Outdoor'],  false),
        makeKiosk(20, 'Maintenance Bay Kiosk',           'outOfService', ['Back of House'], false),
        makeKiosk(21, 'Manager Office Kiosk',            'enabled',     ['Office'],   true),
        makeKiosk(22, 'Group Sales Kiosk',               'enabled',     ['Front Desk'], true),
        makeKiosk(23, 'Express Refill Kiosk 1',          'enabled',     ['Arcade'],   true),
        makeKiosk(24, 'Express Refill Kiosk 2',          'enabled',     ['Arcade'],   true),
        makeKiosk(25, 'Legacy Kiosk (Pre-API)',          null,           ['Storage'],  false),
        makeKiosk(26, 'Old Side Lobby Kiosk',            null,           ['Storage'],  false),
        makeKiosk(27, 'Decommissioned Refill Kiosk',     null,           ['Storage'],  false),
        makeKiosk(28, 'Test Bench Kiosk',                'enabled',     ['Back of House'], true)
    ];

    // --- Pause Groups ---
    // Helper: pull all game IDs for a category id
    function gameIdsForCategory(catId) {
        return games.filter(function(g) {
            return g.categories.some(function(c) { return c.id === catId; });
        }).map(function(g) { return g.game_id; });
    }
    function gameIdsByPrefix(prefix) {
        return games.filter(function(g) { return g.game_name.indexOf(prefix) === 0; })
                    .map(function(g) { return g.game_id; });
    }
    function kioskIdsByCategory(cat) {
        return kiosks.filter(function(k) { return k.categories.indexOf(cat) !== -1; })
                     .map(function(k) { return k.id; });
    }

    var groups = [
        {
            id: 1, name: 'Redemption Floor',
            description: 'All ticket-redemption games on the main floor.',
            is_active: 1,
            game_ids: gameIdsForCategory(1),
            kiosk_ids: kioskIdsByCategory('Redemption'),
            category_ids: [1], schedule_count: 7
        },
        {
            id: 2, name: 'Video Game Wing',
            description: 'Cabinets along the east wall, including light-gun games.',
            is_active: 1,
            game_ids: gameIdsForCategory(2),
            kiosk_ids: [], category_ids: [2], schedule_count: 7
        },
        {
            id: 3, name: 'Kids Zone',
            description: 'Toddler-safe rides and crane games near the family lounge.',
            is_active: 1,
            game_ids: gameIdsForCategory(5),
            kiosk_ids: [], category_ids: [5], schedule_count: 6
        },
        {
            id: 4, name: 'VR Arena',
            description: 'High-power VR pods. Pause overnight to save bulb hours.',
            is_active: 1,
            game_ids: gameIdsForCategory(6),
            kiosk_ids: ['KIOSK-18'], category_ids: [6], schedule_count: 5
        },
        {
            id: 5, name: 'Pinball Row',
            description: 'Pinball machines along the back wall.',
            is_active: 1,
            game_ids: gameIdsForCategory(3),
            kiosk_ids: [], category_ids: [3], schedule_count: 4
        },
        {
            id: 6, name: 'Driving Lineup',
            description: 'All driving and racing cabinets in the south corner.',
            is_active: 1,
            game_ids: gameIdsForCategory(7),
            kiosk_ids: [], category_ids: [7], schedule_count: 7
        },
        {
            id: 7, name: 'Music & Rhythm Floor',
            description: 'DDR / Pump It Up / Sound Voltex stages.',
            is_active: 1,
            game_ids: gameIdsForCategory(8),
            kiosk_ids: [], category_ids: [8], schedule_count: 5
        },
        {
            id: 8, name: 'Light Gun Range',
            description: 'Time Crisis, House of the Dead, and other shooters.',
            is_active: 1,
            game_ids: gameIdsForCategory(9),
            kiosk_ids: [], category_ids: [9], schedule_count: 6
        },
        {
            id: 9, name: 'Sports Corner',
            description: 'Air hockey, mini bowling, foosball, basketball.',
            is_active: 1,
            game_ids: gameIdsForCategory(4),
            kiosk_ids: [], category_ids: [4], schedule_count: 7
        },
        {
            id: 10, name: 'Skee-Ball Lane Bank',
            description: 'All Skee-Ball lanes — paused for nightly recalibration.',
            is_active: 1,
            game_ids: gameIdsByPrefix('Skee-Ball'),
            kiosk_ids: [], category_ids: [], schedule_count: 7
        },
        {
            id: 11, name: 'Crane Bay',
            description: 'Plush cranes — pause when prizes restock.',
            is_active: 1,
            game_ids: gameIdsByPrefix('Plush Crane'),
            kiosk_ids: [], category_ids: [], schedule_count: 4
        },
        {
            id: 12, name: 'Mini Bowling Lanes',
            description: 'All mini bowling lanes for league night control.',
            is_active: 1,
            game_ids: gameIdsByPrefix('Mini Bowling'),
            kiosk_ids: [], category_ids: [], schedule_count: 5
        },
        {
            id: 13, name: 'Air Hockey Tables',
            description: 'All air hockey tables.',
            is_active: 1,
            game_ids: gameIdsByPrefix('Air Hockey'),
            kiosk_ids: [], category_ids: [], schedule_count: 4
        },
        {
            id: 14, name: 'Café Kiosks',
            description: 'Tabletop kiosks at the café — pause during deep clean.',
            is_active: 1,
            game_ids: [],
            kiosk_ids: kioskIdsByCategory('Café'),
            category_ids: [], schedule_count: 3
        },
        {
            id: 15, name: 'Mobile Refill Carts',
            description: 'Roving refill carts on the arcade floor.',
            is_active: 1,
            game_ids: [],
            kiosk_ids: kioskIdsByCategory('Mobile'),
            category_ids: [], schedule_count: 5
        },
        {
            id: 16, name: 'Front Desk Stations',
            description: 'Self-service kiosks at the lobby.',
            is_active: 1,
            game_ids: [],
            kiosk_ids: kioskIdsByCategory('Front Desk'),
            category_ids: [], schedule_count: 7
        },
        {
            id: 17, name: 'Birthday Party Wing',
            description: 'Closed off until a private event books it.',
            is_active: 0,
            game_ids: gameIdsByPrefix('Air Hockey'),
            kiosk_ids: kioskIdsByCategory('Events'),
            category_ids: [], schedule_count: 0
        },
        {
            id: 18, name: 'Outdoor Patio',
            description: 'Patio kiosk — closed in winter months.',
            is_active: 0,
            game_ids: [],
            kiosk_ids: kioskIdsByCategory('Outdoor'),
            category_ids: [], schedule_count: 0
        },
        {
            id: 19, name: 'Closing-Time Maintenance',
            description: 'Pauses the whole arcade for nightly maintenance window.',
            is_active: 1,
            game_ids: [], kiosk_ids: [],
            category_ids: [1, 2, 3, 4, 5, 7, 8, 9], schedule_count: 7
        },
        {
            id: 20, name: 'Storm Day Lockdown',
            description: 'Inactive — used only when severe weather closes the venue.',
            is_active: 0,
            game_ids: [], kiosk_ids: [],
            category_ids: [1, 2, 3, 4, 5, 6, 7, 8, 9],
            schedule_count: 0
        },
        {
            id: 21, name: 'Holiday Extended Hours',
            description: 'Inactive group used for holiday overrides.',
            is_active: 0,
            game_ids: [], kiosk_ids: [],
            category_ids: [1, 2, 3, 4, 5, 6, 7, 8, 9],
            schedule_count: 0
        }
    ];

    // --- Schedules ---
    // Days: 0=Sun..6=Sat. Castle Fun Center sample hours.
    var schedules = [];
    var nextSchedId = 100;

    // Helper: build a 7-day schedule for a group with daily start/end times.
    // hours = { 0:[start,end,active?], 1:[..], ... } — missing days are skipped.
    function addSchedules(groupId, groupName, hours) {
        Object.keys(hours).forEach(function(dow) {
            var h = hours[dow];
            if (!h) return;
            schedules.push({
                id: ++nextSchedId,
                pause_group_id: groupId,
                group_name: groupName,
                day_of_week: parseInt(dow, 10),
                start_time: h[0],
                end_time: h[1],
                is_active: h[2] !== undefined ? h[2] : 1
            });
        });
    }

    // Standard "open all week" hours used by most floor sections.
    var standardHours = {
        0: ['11:00','21:00'], 1: ['11:00','21:00'], 2: ['11:00','21:00'],
        3: ['11:00','21:00'], 4: ['11:00','21:00'],
        5: ['11:00','23:00'], 6: ['10:00','23:00']
    };
    var earlyCloseHours = {
        0: ['11:00','19:00'], 1: ['11:00','19:00'], 2: ['11:00','19:00'],
        3: ['11:00','19:00'], 4: ['11:00','19:00'],
        5: ['11:00','20:00'], 6: ['10:00','20:00']
    };
    var weekendOnlyHours = {
        4: ['15:00','21:00'], 5: ['15:00','23:00'],
        6: ['12:00','23:00'], 0: ['12:00','21:00']
    };
    var maintenanceHours = {
        0: ['23:30','23:55',1], 1: ['23:30','23:55',1], 2: ['23:30','23:55',1],
        3: ['23:30','23:55',1], 4: ['23:30','23:55',1],
        5: ['23:30','23:55',1], 6: ['23:30','23:55',1]
    };

    // Apply schedules for the major groups
    addSchedules(1,  'Redemption Floor',     standardHours);
    addSchedules(2,  'Video Game Wing',      standardHours);
    addSchedules(3,  'Kids Zone',            earlyCloseHours);
    addSchedules(4,  'VR Arena',             weekendOnlyHours);
    addSchedules(5,  'Pinball Row',          standardHours);
    addSchedules(6,  'Driving Lineup',       standardHours);
    addSchedules(7,  'Music & Rhythm Floor', { 4: ['15:00','22:00'], 5: ['11:00','23:00'], 6: ['10:00','23:00'], 0: ['11:00','21:00'], 3: ['15:00','21:00',0] });
    addSchedules(8,  'Light Gun Range',      standardHours);
    addSchedules(9,  'Sports Corner',        standardHours);
    addSchedules(10, 'Skee-Ball Lane Bank',  standardHours);
    addSchedules(11, 'Crane Bay',            { 4: ['15:00','21:00'], 5: ['11:00','23:00'], 6: ['10:00','23:00'], 0: ['11:00','21:00'] });
    addSchedules(12, 'Mini Bowling Lanes',   standardHours);
    addSchedules(13, 'Air Hockey Tables',    standardHours);
    addSchedules(14, 'Café Kiosks',          earlyCloseHours);
    addSchedules(15, 'Mobile Refill Carts',  standardHours);
    addSchedules(16, 'Front Desk Stations',  standardHours);
    addSchedules(19, 'Closing-Time Maintenance', maintenanceHours);

    // --- Overrides ---
    // Multiple active, upcoming, and expired overrides so the page can
    // demonstrate per-section pagination with realistic volume.
    var managers = ['Manager Alex', 'Operator Pat', 'Tech Casey', 'Manager Riley'];
    function ovr(id, gid, gname, name, action, startD, endD, who) {
        return {
            id: id,
            pause_group_id: gid,
            group_name: gname,
            name: name,
            action: action,
            start_datetime: sqlUtc(startD),
            end_datetime: sqlUtc(endD),
            created_by_name: who
        };
    }

    var overrides = {
        active: [
            ovr(901, 1,  'Redemption Floor',     'Smith Family Birthday Party',         'unpause', offsetHour(-1),  offsetMin(45),  managers[0]),
            ovr(902, 4,  'VR Arena',              'Birthday Add-On VR Hour',             'unpause', offsetMin(-30),  offsetMin(60),  managers[1]),
            ovr(903, 11, 'Crane Bay',             'Crane Restock — Bay 2 Maintenance',   'pause',   offsetMin(-15),  offsetMin(30),  managers[2])
        ],
        upcoming: [
            ovr(910, 19, 'Closing-Time Maintenance', 'Q2 System Update',                  'pause',   offsetDay(1),    new Date(offsetDay(1).getTime() + 2 * 3600 * 1000), managers[1]),
            ovr(911, 4,  'VR Arena',                 'Bulb Replacement — Bay 1',          'pause',   offsetDay(2),    new Date(offsetDay(2).getTime() + 4 * 3600 * 1000), managers[2]),
            ovr(912, 6,  'Driving Lineup',           'Steering Calibration',              'pause',   offsetDay(3),    new Date(offsetDay(3).getTime() + 1.5 * 3600 * 1000), managers[2]),
            ovr(913, 7,  'Music & Rhythm Floor',     'DDR Tournament Prep',               'pause',   offsetDay(4),    new Date(offsetDay(4).getTime() + 3 * 3600 * 1000), managers[0]),
            ovr(914, 17, 'Birthday Party Wing',      'Johnson Party',                     'unpause', offsetDay(2),    new Date(offsetDay(2).getTime() + 5 * 3600 * 1000), managers[3]),
            ovr(915, 17, 'Birthday Party Wing',      'Garcia Party',                      'unpause', offsetDay(5),    new Date(offsetDay(5).getTime() + 4 * 3600 * 1000), managers[3]),
            ovr(916, 21, 'Holiday Extended Hours',   'July 4 Late Hours',                 'unpause', offsetDay(7),    new Date(offsetDay(7).getTime() + 8 * 3600 * 1000), managers[0]),
            ovr(917, 8,  'Light Gun Range',          'Recoil Sensor Recalibration',       'pause',   offsetDay(6),    new Date(offsetDay(6).getTime() + 2 * 3600 * 1000), managers[2]),
            ovr(918, 9,  'Sports Corner',            'Floor Wax Drying',                  'pause',   offsetDay(8),    new Date(offsetDay(8).getTime() + 3 * 3600 * 1000), managers[1]),
            ovr(919, 14, 'Café Kiosks',              'Café Deep Clean',                   'pause',   offsetDay(9),    new Date(offsetDay(9).getTime() + 5 * 3600 * 1000), managers[1])
        ],
        expired: []
    };

    // Generate a healthy expired-overrides history (40 entries) — covers
    // a few months of operation so the "Expired" tab paginates.
    (function buildExpiredOverrides() {
        var sampleNames = [
            'Spring Break Extended Hours', 'Easter Holiday Hours', 'Summer Camp Visit',
            'Birthday Party — Patel Family', 'Birthday Party — Khan Family',
            'School Field Trip', 'Cub Scouts Visit', 'Boy Scouts Visit',
            'Group Sales — Insurance Co', 'Group Sales — Sales Kickoff',
            'Bulb Replacement', 'Floor Tile Repair', 'POS Update',
            'WiFi Maintenance', 'AC Repair', 'Power Wash Patio',
            'Mascot Photoshoot', 'Press Visit', 'Mystery Shopper Window',
            'New Year’s Late Night', 'Halloween Extended Hours',
            'Memorial Day Hours', 'Labor Day Hours', 'Thanksgiving Eve Hours',
            'Christmas Eve Early Close', 'Black Friday Late Open',
            'Valentine’s Couple Hours', 'St. Patrick’s Promo',
            'Mother’s Day Brunch', 'Father’s Day Brunch'
        ];
        var groupChoices = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 17, 19];
        for (var i = 0; i < 40; i++) {
            var n = sampleNames[i % sampleNames.length] + (i >= sampleNames.length ? ' (' + Math.floor(i / sampleNames.length + 1) + ')' : '');
            var gId = groupChoices[i % groupChoices.length];
            var grp = groups.find(function(g) { return g.id === gId; });
            var endDaysAgo = 2 + i * 3;
            var durHours = 1 + (i % 6);
            var endD = offsetDay(-endDaysAgo);
            var startD = new Date(endD.getTime() - durHours * 3600 * 1000);
            overrides.expired.push(ovr(800 - i, gId, grp ? grp.name : 'Unknown',
                n, (i % 3 === 0 ? 'pause' : 'unpause'),
                startD, endD, managers[i % managers.length]));
        }
    })();

    // --- Recent transactions / live feed ---
    // Some games are "hot" (give out tickets prolifically), some are middling,
    // and some are quiet today. This per-game weighting drives the ticket
    // monitoring widgets so the demo looks realistic. Out-of-service games
    // always have zero plays (matching reality).
    // Hot games — high-traffic redemption + popular video cabinets across
    // the floor. Mid games are middle-of-the-pack pull. Everything else is
    // quiet/low-volume.
    var hotGames = [
        'GAME-001','GAME-002','GAME-003','GAME-004','GAME-005',  // Skee-Ball lanes (very popular)
        'GAME-006','GAME-007','GAME-008','GAME-009','GAME-010',  // top redemption
        'GAME-011','GAME-012','GAME-018','GAME-019','GAME-020',  // wheel/drop/jackpot redemption
        'GAME-021','GAME-022','GAME-023','GAME-024','GAME-025',  // more redemption
        'GAME-057','GAME-058','GAME-059',                         // air hockey
        'GAME-091','GAME-092','GAME-093'                          // driving cabinets
    ];
    var midGames = [
        'GAME-013','GAME-014','GAME-015','GAME-016','GAME-017',
        'GAME-026','GAME-027','GAME-028','GAME-029','GAME-030','GAME-031','GAME-032',
        'GAME-033','GAME-034','GAME-035','GAME-036','GAME-037','GAME-038',
        'GAME-060','GAME-061','GAME-062','GAME-063','GAME-064',
        'GAME-080','GAME-081','GAME-082','GAME-083','GAME-084',
        'GAME-101','GAME-102','GAME-103','GAME-104'
    ];

    function gameWeight(gameId) {
        if (hotGames.indexOf(gameId) !== -1) return 5;
        if (midGames.indexOf(gameId) !== -1) return 2;
        return 1;
    }

    function pickWeighted(items) {
        var totalWeight = 0;
        var weights = items.map(function(g) {
            var w = gameWeight(g.game_id);
            totalWeight += w;
            return w;
        });
        var r = Math.random() * totalWeight;
        var acc = 0;
        for (var i = 0; i < items.length; i++) {
            acc += weights[i];
            if (r < acc) return items[i];
        }
        return items[items.length - 1];
    }

    /**
     * Generate a realistic ticket count for a game/play combination.
     * Hot games occasionally award jackpot-sized 200+ ticket payouts;
     * others stay in normal 0-150 range. Returns 0 if the play didn't
     * award tickets at all.
     */
    function generateTickets(gameId, isJackpot) {
        if (Math.random() < 0.30) return 0;  // 30% of plays give no tickets
        if (hotGames.indexOf(gameId) !== -1) {
            if (isJackpot) return 200 + Math.floor(Math.random() * 800);
            return 30 + Math.floor(Math.random() * 220);
        }
        if (midGames.indexOf(gameId) !== -1) {
            if (isJackpot) return 100 + Math.floor(Math.random() * 250);
            return 10 + Math.floor(Math.random() * 110);
        }
        return Math.floor(Math.random() * 50);
    }

    var transactions = [];
    (function buildTransactions() {
        var pickGames = games.filter(function(g) { return g.operation_status !== 'outOfService'; });
        var cards = [
            '80009100','80009103','80009107','80009110','80009111',
            '80009125','80009126','80009127','80009134','80009144',
            '80009201','80009212','80009223','80009234','80009245',
            '80009312','80009333','80009345','80009366','80009377',
            '80009401','80009418','80009429','80009440','80009455',
            '80009501','80009512','80009523','80009534','80009545'
        ];
        // Build ~900 plays spread over the last 7 days, denser in the last
        // hour. With 100+ games on the floor the live feed needs enough volume
        // for the new search + pagination controls to be exercised.
        for (var i = 0; i < 900; i++) {
            var g = pickWeighted(pickGames);
            // Time distribution: ~30 in last hour, ~80 today, rest within 7 days
            var minsAgo;
            if (i < 90) minsAgo = Math.floor(Math.random() * 60);            // last hour
            else if (i < 320) minsAgo = 60 + Math.floor(Math.random() * 600); // earlier today
            else minsAgo = Math.floor(60 + Math.pow(i - 320, 1.4) * 3 + Math.random() * 30);
            var noCard = Math.random() < 0.08;
            var rp = Math.random() < 0.3 ? 0 : (Math.floor(Math.random() * 5) + 1);
            var bp = Math.random() < 0.7 ? 0 : (Math.floor(Math.random() * 3) + 1);
            var isJackpot = Math.random() < 0.04;
            var tickets = generateTickets(g.game_id, isJackpot);
            transactions.push({
                transaction_time: isoUtc(offsetMin(-minsAgo)),
                game_id: g.game_id,
                game_name: g.game_name,
                card_number: noCard ? null : cards[Math.floor(Math.random() * cards.length)],
                no_card: noCard,
                used_time_play: Math.random() < 0.12,
                used_play_privilege: Math.random() < 0.07,
                regular_points: rp,
                bonus_points: bp,
                redemption_tickets: tickets
            });
        }
        transactions.sort(function(a, b) {
            return a.transaction_time < b.transaction_time ? 1 : -1;
        });
    })();

    // --- Synthetic long-range performance history (per game, per day) ---
    // The raw `transactions` feed above only spans a week (it powers the live
    // feed). The Performance page reports by month and year, so we generate a
    // deterministic-ish daily rollup going back ~14 months — mirroring the real
    // game_daily_stats table the backend fills nightly. Weekends and a gentle
    // seasonal wave shape volume; a stable per-game weight keeps each game's
    // ranking consistent from day to day.
    var perfDailyStats = [];
    (function buildPerfDailyStats() {
        var pool = games.filter(function(g) { return g.operation_status !== 'outOfService'; });
        var DAYS = 420;
        function gameWeight(id) {
            var h = 0;
            for (var i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) >>> 0;
            return 0.4 + (h % 1000) / 1000 * 1.9; // stable 0.4..2.3 per game
        }
        for (var off = -DAYS; off <= 0; off++) {
            var d = offsetDay(off);
            var dateStr = localDate(d);
            var dow = d.getDay();
            var weekend = dow === 5 ? 1.4 : dow === 6 ? 1.75 : dow === 0 ? 1.5 : 1.0;
            var seasonal = 1 + 0.22 * Math.sin((off / 365) * 2 * Math.PI + 1);
            var dayScale = off === 0 ? 0.6 : 1.0; // today is only partway through
            for (var gi = 0; gi < pool.length; gi++) {
                var g = pool[gi];
                var gw = gameWeight(g.game_id);
                var base = 3.0 * gw * weekend * seasonal * dayScale;
                var plays = Math.round(base * (0.7 + Math.random() * 0.6));
                if (plays <= 0) continue;
                var perPlayTix = 3 + gw * 4.5; // ~4.8..13.4 avg tickets/play
                var tickets = Math.round(plays * perPlayTix * (0.8 + Math.random() * 0.4));
                var cash = Math.round(plays * (0.35 + gw * 0.45) * 100) / 100;
                perfDailyStats.push({
                    stat_date: dateStr,
                    game_id: g.game_id,
                    game_name: g.game_name,
                    plays: plays,
                    tickets: tickets,
                    cash: cash
                });
            }
        }
    })();

    /**
     * Compute per-game ticket/play aggregates that match the response shape
     * of /api/games/transactions/stats. The dashboard and games-page widgets
     * read this. Returns { stats: {gameId: {...}}, totals: {...}, windows }.
     */
    function ticketStats() {
        var nowMs = Date.now();
        var hourCutoff = nowMs - 3600000;
        var todayCutoffD = new Date(now); todayCutoffD.setHours(0, 0, 0, 0);
        var todayCutoff = todayCutoffD.getTime();
        var weekCutoff = nowMs - 7 * 86400000;

        var stats = {};
        var totals = {
            tickets_hour: 0, plays_hour: 0,
            tickets_today: 0, plays_today: 0,
            tickets_week: 0, plays_week: 0,
            tickets_all: 0, plays_all: 0
        };

        function bumpStat(s, kP, kT, tickets) {
            s[kP] = (s[kP] || 0) + 1;
            s[kT] = (s[kT] || 0) + (tickets || 0);
            totals[kP] += 1;
            totals[kT] += (tickets || 0);
        }

        transactions.forEach(function(t) {
            var ms = new Date(t.transaction_time).getTime();
            if (!t.game_id) return;
            var s = stats[t.game_id] || (stats[t.game_id] = {
                plays_hour: 0, tickets_hour: 0,
                plays_today: 0, tickets_today: 0,
                plays_week: 0, tickets_week: 0,
                plays_all: 0, tickets_all: 0,
                last_play: null
            });
            var tk = t.redemption_tickets || 0;
            bumpStat(s, 'plays_all', 'tickets_all', tk);
            if (ms >= weekCutoff) bumpStat(s, 'plays_week', 'tickets_week', tk);
            if (ms >= todayCutoff) bumpStat(s, 'plays_today', 'tickets_today', tk);
            if (ms >= hourCutoff) bumpStat(s, 'plays_hour', 'tickets_hour', tk);
            if (!s.last_play || t.transaction_time > s.last_play) s.last_play = t.transaction_time;
        });

        return {
            stats: stats,
            totals: totals,
            windows: {
                hour: new Date(hourCutoff).toISOString(),
                today: new Date(todayCutoff).toISOString(),
                week: new Date(weekCutoff).toISOString()
            },
            last_poll_at: lastPollAt,
            total_cached: transactions.length
        };
    }

    function topGames(window, sort) {
        var cutoff;
        if (window === 'hour')      cutoff = offsetMin(-60).getTime();
        else if (window === 'today') cutoff = (function() {
            var d = new Date(now); d.setHours(0,0,0,0); return d.getTime();
        })();
        else if (window === 'week') cutoff = offsetDay(-7).getTime();
        else                         cutoff = 0;

        var counts = {};
        transactions.forEach(function(t) {
            var ts = new Date(t.transaction_time).getTime();
            if (ts < cutoff) return;
            var key = t.game_id;
            if (!counts[key]) {
                counts[key] = { game_id: t.game_id, game_name: t.game_name, plays: 0, sum_tickets: 0 };
            }
            counts[key].plays += 1;
            counts[key].sum_tickets += t.redemption_tickets || 0;
        });
        var arr = Object.keys(counts).map(function(k) { return counts[k]; });
        // Default sort matches the widget label ("Top games by tickets").
        // Plays act as a tiebreaker so two games with identical ticket totals
        // still rank deterministically.
        if (sort === 'plays') {
            arr.sort(function(a, b) {
                return (b.plays - a.plays) || (b.sum_tickets - a.sum_tickets);
            });
        } else {
            arr.sort(function(a, b) {
                return (b.sum_tickets - a.sum_tickets) || (b.plays - a.plays);
            });
        }
        return arr;
    }

    // --- Card lookup data (single sample card) ---
    var sampleCards = {
        '80009100': {
            cardNumber: '80009100',
            issuedAtTime: isoUtc(offsetDay(-90)),
            balance: { regularPoints: 247, bonusPoints: 18, redemptionTickets: 4218 },
            timePlays: [
                { type: 'minutes', minutesRemaining: 32, groupId: 1, expirationDateTime: isoUtc(offsetDay(2)), suppressTickets: false },
                { type: 'dateTimeRange', start: isoUtc(offsetMin(-90)), end: isoUtc(offsetMin(90)), groupId: 2, expirationDateTime: isoUtc(offsetMin(90)) }
            ],
            privileges: [
                { count: 3, groupId: 1, expirationDateTime: isoUtc(offsetDay(7)) },
                { count: 1, groupId: 6, expirationDateTime: isoUtc(offsetDay(30)) }
            ]
        }
    };

    var sampleCardTransactions = [];
    (function buildCardTx() {
        for (var i = 0; i < 220; i++) {
            var g = games[Math.floor(Math.random() * games.length)];
            var isPlay = Math.random() < 0.85;
            sampleCardTransactions.push(isPlay ? {
                transactionTime: isoUtc(offsetMin(-(i * 17 + Math.floor(Math.random() * 7)))),
                type: 'gamePlay',
                gameId: g.game_id,
                gameDescription: g.game_name,
                usedTimePlay: Math.random() < 0.1,
                usedPlayPrivilege: Math.random() < 0.08,
                amount: { regularPoints: Math.floor(Math.random() * 4) + 1, bonusPoints: 0, redemptionTickets: Math.floor(Math.random() * 80) }
            } : {
                transactionTime: isoUtc(offsetMin(-(i * 23 + 11))),
                type: 'adjustment',
                amountPaid: Math.random() < 0.5 ? 20 : 0,
                adjustments: [
                    { type: 'addValue', amount: { regularPoints: 100, bonusPoints: 25, redemptionTickets: 0 } }
                ]
            });
        }
    })();

    // --- Logs ---
    var logs = [];
    (function buildLogs() {
        var sources = ['cron','manual','override','schedule'];
        var actions = ['pause','unpause','plan_day','execute_action','skip'];
        var managers = ['Manager Alex','Operator Pat','Tech Casey'];
        for (var i = 0; i < 480; i++) {
            var src = sources[Math.floor(Math.random() * sources.length)];
            var act = actions[Math.floor(Math.random() * actions.length)];
            var grp = groups[Math.floor(Math.random() * groups.length)];
            var success = Math.random() > 0.06;
            var minsAgo = Math.floor(i * 11 + Math.random() * 9);
            var entry = {
                id: 10000 - i,
                timestamp: sqlUtc(offsetMin(-minsAgo)),
                source: src,
                action: act,
                group_name: grp.name,
                game_name: act === 'pause' || act === 'unpause' ? games[Math.floor(Math.random() * games.length)].game_name : null,
                error_message: success ? null : (Math.random() < 0.5
                    ? 'CenterEdge API error (HTTP 503)'
                    : 'Game not in expected state — retried'),
                success: success ? 1 : 0,
                triggered_by: src === 'manual' ? managers[Math.floor(Math.random() * managers.length)] : null
            };
            logs.push(entry);
        }
    })();

    // --- Users ---
    // The demo seeds one user per role so the role switcher (in demo-controls.js)
    // can flip the signed-in identity instantly. Roles match the live backend:
    //   admin   — full access
    //   manager — full access except Settings
    //   tech    — full access except sales data (Analytics, Card Lookup)
    var users = [
        { id: 1, username: 'admin',     display_name: 'Owner Avery',     role: 'admin',   is_active: 1, created_at: sqlUtc(offsetDay(-180)) },
        { id: 2, username: 'morgan',    display_name: 'Manager Morgan',  role: 'manager', is_active: 1, created_at: sqlUtc(offsetDay(-120)) },
        { id: 3, username: 'casey',     display_name: 'Tech Casey',      role: 'tech',    is_active: 1, created_at: sqlUtc(offsetDay(-60)) },
        { id: 4, username: 'oldstaff',  display_name: 'Former Cashier',  role: 'tech',    is_active: 0, created_at: sqlUtc(offsetDay(-300)) }
    ];

    // --- Settings ---
    var settings = {
        base_url: 'https://castlefun.centeredge.io/api/v1',
        username: 'pause-groups-svc',
        password: '',  // never echoed back from real backend either
        api_key: '',
        timezone: 'America/New_York'
    };

    // Capabilities mock — matches the shape capabilities.php returns.
    var capabilities = {
        kiosks: { isSupported: true, operationStatus: true, list: true, get: true },
        games: { operationStatus: true, list: true, get: true, supportedActions: true }
    };

    // Health
    function buildHealth() {
        return {
            status: 'ok',
            database: true,
            cron:     { last_run: sqlUtc(offsetMin(-(now.getMinutes() + 5))), age_seconds: (now.getMinutes() + 5) * 60, healthy: true },
            watchdog: { last_run: sqlUtc(offsetMin(-1)), age_seconds: 30, healthy: true },
            warnings: [
                'install.php is still web-accessible. Remove or block it in your web server config.'
            ]
        };
    }

    /**
     * Recompute group derived stats based on current game/kiosk state plus
     * any active overrides or manual overrides. The dashboard expects the
     * server to do this; the mock has to keep it in sync.
     */
    function expandGroupGames(group) {
        var ids = {};
        (group.game_ids || []).forEach(function(id) { ids[id] = true; });
        (group.category_ids || []).forEach(function(catId) {
            games.forEach(function(g) {
                if (g.categories.some(function(c) { return c.id === catId; })) ids[g.game_id] = true;
            });
        });
        return Object.keys(ids);
    }

    function expandGroupKiosks(group) {
        var ids = {};
        (group.kiosk_ids || []).forEach(function(id) { ids[id] = true; });
        return Object.keys(ids);
    }

    // Last sync timestamps
    var lastSync = {
        games: sqlUtc(offsetMin(-3)),
        kiosks: sqlUtc(offsetMin(-3))
    };

    // Last poll (transaction feed)
    var lastPollAt = isoUtc(offsetMin(-1));

    // Manual-override state per group, keyed by group id.
    var manualOverrides = {};  // empty by default

    // Expose data store
    window.MockData = {
        now: now,
        isoUtc: isoUtc,
        sqlUtc: sqlUtc,
        offsetMin: offsetMin,
        offsetHour: offsetHour,
        offsetDay: offsetDay,

        categories: categories,
        games: games,
        gameDetails: gameDetails,
        kiosks: kiosks,
        groups: groups,
        schedules: schedules,
        overrides: overrides,
        transactions: transactions,
        perfDailyStats: perfDailyStats,
        topGames: topGames,
        ticketStats: ticketStats,
        sampleCards: sampleCards,
        sampleCardTransactions: sampleCardTransactions,
        logs: logs,
        users: users,
        settings: settings,
        capabilities: capabilities,
        buildHealth: buildHealth,
        expandGroupGames: expandGroupGames,
        expandGroupKiosks: expandGroupKiosks,
        lastSync: lastSync,
        lastPollAt: lastPollAt,
        manualOverrides: manualOverrides
    };
})();
