<?php
// ==================== PHP SERVER - داناکوین آنلاین - با تغییرات جدید و ایمن (Atomic Write) ====================



$dataFile = 'data.json';
$lockFile = $dataFile . '.lock';
$tempFile = $dataFile . '.tmp';

// ---------------------------------------------------------------------------------
// توابع مدیریت فایل ایمن (Safe File Management)
// ---------------------------------------------------------------------------------

/**
 * ایمن خواندن داده‌ها از فایل JSON با استفاده از file locking (مشترک)
 * @param string $filename
 * @return array
 */
function loadDataSafe($filename) {
    $lockFile = $filename . '.lock';
    $data = [
        'users' => [], 
'prices' => ['BTC'=>0,'ETH'=>0,'BNB'=>0,'SOL'=>0,'TAO'=>0,'AAVE'=>0,'BCH'=>0,'ZEC'=>0,'XMR'=>0,'LTC'=>0],
        'lastPriceUpdate' => 0,
        'news' => [],
        'sponsors' => []
    ];
    $fp = fopen($lockFile, 'c+'); // ایجاد فایل قفل اگر وجود ندارد
    if ($fp === false) {
        // Fallback: تلاش برای خواندن مستقیم اگر قفل ناموفق بود
        if (file_exists($filename)) {
            $content = file_get_contents($filename);
            return json_decode($content, true) ?? $data;
        }
        return $data;
    }

    if (flock($fp, LOCK_SH)) { // قفل خواندن مشترک
        if (file_exists($filename)) {
            $content = file_get_contents($filename);
            $readData = json_decode($content, true);
            if (is_array($readData)) {
                $data = array_merge($data, $readData);
            }
        }
        flock($fp, LOCK_UN); // باز کردن قفل
    }
    fclose($fp);
    return $data;
}

/**
 * ایمن نوشتن داده‌ها در فایل JSON با استفاده از file locking (اختصاصی) و atomic rename
 * @param string $filename
 * @param array $data
 * @return bool
 */
function saveDataSafe($filename, $data) {
    $lockFile = $filename . '.lock';
    $tempFile = $filename . '.tmp';
    $fp = fopen($lockFile, 'c+');
    if ($fp === false) return false;

    $success = false;
    if (flock($fp, LOCK_EX)) { // قفل نوشتن اختصاصی
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json !== false) {
            // نوشتن در فایل موقت
            if (file_put_contents($tempFile, $json) !== false) {
                // جابجایی atomic
                if (rename($tempFile, $filename)) {
                    $success = true;
                } else {
                    // اگر rename شکست خورد، فایل موقت را حذف کن
                    @unlink($tempFile);
                }
            }
        }
        flock($fp, LOCK_UN); // باز کردن قفل
    }
    fclose($fp);
    return $success;
}

// ---------------------------------------------------------------------------------
// لود داده‌های اولیه
// ---------------------------------------------------------------------------------
// 📢 استفاده از تابع ایمن لود
$data = loadDataSafe($dataFile);
// ——————————————————————————————————————
// تبدیل خودکار کاربران قدیمی به سیستم جدید کلیک (5 → 10 → 20 → 40 ...)
// فقط یکبار اجرا میشه و بعدش دیگه کاری نمیکنه
// ——————————————————————————————————————

if (!isset($data['totalMinersBought'])) {
    $data['totalMinersBought'] = 0;
}

if (is_array($data['users'] ?? null)) {
    $needSave = false;
    foreach ($data['users'] as $username => &$user) {
        
        // ۱. تبدیل کاربران خیلی قدیمی (که multiplier داشتن)
        if (!isset($user['click_level']) && isset($user['multiplier'])) {
            $oldMultiplier = max(1, (int)$user['multiplier']);
            $user['click_level'] = $oldMultiplier - 1;
            $user['click_power']  = pow(2, $user['click_level']);
            $user['upgradeCost'] = 500 * pow(2, $user['click_level']);  // ← جدید: 10000
            unset($user['multiplier']);
            $needSave = true;
        }
        
        // ۲. تنظیم click_power و upgradeCost برای کاربرانی که این فیلدها رو ندارن
        if (!isset($user['click_power'])) {
            $level = $user['click_level'] ?? 0;
            $user['click_power'] = 1 * pow(2, $level);
            $needSave = true;
        }
        
        // ۳. تنظیم upgradeCost اگر اصلاً وجود نداشته باشه یا قدیمی باشه
        if (!isset($user['upgradeCost']) || $user['upgradeCost'] < 10000) {
            $level = $user['click_level'] ?? 0;
            $user['upgradeCost'] = 5000 * pow(2, $level);  // ← همیشه بر اساس فرمول جدید
            $needSave = true;
        }
    }
    unset($user);

    if ($needSave) {
        saveDataSafe($dataFile, $data);
        $data = loadDataSafe($dataFile);
    }
}
// ——————————————————————————————————————

// ---------------------------------------------------------------------------------
// تعریف حساب ادمین (Admin Account)
// ---------------------------------------------------------------------------------
$adminUsername = 'admin';
$adminPass = 'sj88';

// اگر حساب ادمین وجود ندارد، آن را با تنظیمات خاص ایجاد می‌کنیم
if (!isset($data['users'][$adminUsername])) {
    $data['users'][$adminUsername] = [
        'pass' => $adminPass,
        'balance' => 999999999, // موجودی بالا برای ادمین
        'clicks' => 0, 
'click_level' => 0, 
'click_power' => 1, 
'upgradeCost' => 5000,
'crypto' => [
    'BTC'=>0,'ETH'=>0,'BNB'=>0,'SOL'=>0,'TAO'=>0,'AAVE'=>0,'BCH'=>0,'ZEC'=>0,'XMR'=>0,'LTC'=>0,
    'YFI'=>0,'PAXG'=>0,'WBTC'=>0,'OKB'=>0
],
        'soldiers' => 0, 'guards' => 0, 'barrackSlots' => 0, 'guardSlots' => 0,
        'is_banned' => false,
        'is_helper' => false,
        'is_admin' => true, // پرچم ادمین
        'lastAttackTime' => 0, // 📢 تغییر ۱: فیلد جدید محدودیت حمله
        'totalEarned' => 999999999,         // ادمین هم باید داشته باشه
'totalCryptoBought' => 0,
'totalCryptoSold' => 0,
    'miners' => [],  
    'totalMinersBought' => 0
    ];
    // 📢 ذخیره ایمن
    saveDataSafe($dataFile, $data);
    // لود مجدد برای اعمال تغییر
    $data = loadDataSafe($dataFile);
}


// ---------------------------------------------------------------------------------
// توابع مدیریت خبر (News Management)
// ---------------------------------------------------------------------------------
function addNews($data, $message, $targetUser = null) {
    // این تابع خبر را به لیست سراسری خبرها اضافه می‌کند.
    // اگر targetUser مشخص باشد، خبر فقط به آن کاربر نمایش داده می‌شود.
    $newsItem = [
        'timestamp' => time() * 1000,
        'message' => $message,
        'target' => $targetUser, // نام کاربری هدف (اگر عمومی نباشد)
    ];
    // حداکثر ۱۰۰ خبر آخر را نگه می‌داریم
    $data['news'][] = $newsItem;
    $data['news'] = array_slice($data['news'], -100); 
    return $data;
}


// ---------------------------------------------------------------------------------
// به‌روزرسانی قیمت‌های واقعی از CoinGecko (دقیقاً قیمت دلار جهانی)
// ---------------------------------------------------------------------------------
function updatePricesIfNeeded(&$data) {
    // هر ۶۰ ثانیه یکبار قیمت‌ها رو از بازار واقعی می‌کشیم
    $updateInterval = 60;

    if (time() - ($data['lastPriceUpdate'] / 1000) > $updateInterval) {
        
        $ids = 'bitcoin,ethereum,binancecoin,solana,bittensor,aave,bitcoin-cash,zcash,monero,litecoin,yearn-finance,pax-gold,wrapped-bitcoin,okb';
        $url = "https://api.coingecko.com/api/v3/simple/price?ids={$ids}&vs_currencies=usd";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'DanaCoinBot/1.0');
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response) {
            $prices = json_decode($response, true);
            if ($prices) {
      $data['prices'] = [
    'BTC'   => floor($prices['bitcoin']['usd'] ?? 0),
    'ETH'   => floor($prices['ethereum']['usd'] ?? 0),
    'BNB'   => floor($prices['binancecoin']['usd'] ?? 0),
    'SOL'   => floor($prices['solana']['usd'] ?? 0),
    'TAO'   => floor($prices['bittensor']['usd'] ?? 0),
    'AAVE'  => floor($prices['aave']['usd'] ?? 0),
    'BCH'   => floor($prices['bitcoin-cash']['usd'] ?? 0),
    'ZEC'   => floor($prices['zcash']['usd'] ?? 0),
    'XMR'   => floor($prices['monero']['usd'] ?? 0),
    'LTC'   => floor($prices['litecoin']['usd'] ?? 0),
    'YFI'   => floor($prices['yearn-finance']['usd'] ?? 0),
    'PAXG'  => floor($prices['pax-gold']['usd'] ?? 0),
    'WBTC'  => floor($prices['wrapped-bitcoin']['usd'] ?? 0),
    'OKB'   => floor($prices['okb']['usd'] ?? 0),
];
                $data['lastPriceUpdate'] = time() * 1000;
            }
        }
    }
    return $data;
}


// ---------------------------------------------------------------------------------
// بخش POST
// ---------------------------------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') 
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // 📢 در هر اکشن POST که داده‌ها را تغییر می‌دهد، ابتدا داده‌ها را لود کرده، تغییر اعمال شده و سپس ذخیره می‌شوند.
    // 📢 برای هر اکشن، داده‌ها را مجدداً لود می‌کنیم تا از جدیدترین نسخه استفاده کنیم و race condition را کاهش دهیم.
    
      // ثبت نام جدید + ورود خودکار
      if ($action === 'register') {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $u = trim($input['username']);
        $p = $input['pass'];
        if (empty($u) || empty($p)) {
            echo json_encode(['success'=>false, 'msg'=>'نام کاربری و رمز الزامی است']);
        } elseif (isset($data['users'][$u])) {
            echo json_encode(['success'=>false, 'msg'=>'این نام کاربری قبلاً ثبت شده']);
        } else {
            $data['users'][$u] = [
                'pass' => $p,
                'balance' => 1000,
                'clicks' => 0,
                'click_level' => 0,           
                'click_power' => 1,           
                'upgradeCost' => 5000,         
                'crypto' => [
                    'BTC'=>0,'ETH'=>0,'BNB'=>0,'SOL'=>0,'TAO'=>0,'AAVE'=>0,'BCH'=>0,'ZEC'=>0,'XMR'=>0,'LTC'=>0,
                    'YFI'=>0,'PAXG'=>0,'WBTC'=>0,'OKB'=>0
                ],    
                'soldiers' => 0, 'guards' => 0, 'barrackSlots' => 0, 'guardSlots' => 0,
                'is_banned' => false,
                'is_helper' => false,
                'is_admin' => false,
                'lastAttackTime' => 0,
                'mine_ban_end' => 0,        
                'mine_ban_level' => 0,       
                'totalEarned' => 1000,          
                'totalCryptoBought' => 0,       
                'totalCryptoSold' => 0,
                'miners' => [],  
                'totalMinersBought' => 0     
            ];

            // اضافه کردن خبر خوش‌آمدگویی خصوصی (فقط برای کاربر جدید可见)
            $data = addNews($data, "🎉 خوش آمدید {$u}! حساب شما با موفقیت ایجاد شد.\nحالا می‌تونی ماین کنی، ماشین بخری و داناکوین جمع کنی!\nموفق باشی 🚀", $u);

            saveDataSafe($dataFile, $data); // ذخیره ایمن

            // پاسخ مشابه لاگین → کلاینت می‌تونه مستقیم وارد حساب کنه
            echo json_encode([
                'success' => true,
                'is_admin' => false
            ]);
        }
        exit;
    }

    // ورود به حساب
    if ($action === 'login') {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $u = trim($input['username']);
        $p = $input['pass'];
        if (isset($data['users'][$u]) && $data['users'][$u]['pass'] === $p) {
            if ($data['users'][$u]['is_banned']) {
                 echo json_encode(['success'=>false, 'msg'=>'حساب شما مسدود شده است.']);
            } else {
                 echo json_encode(['success'=>true, 'is_admin'=>$data['users'][$u]['is_admin']]);
            }
        } else {
            echo json_encode(['success'=>false, 'msg'=>'نام کاربری یا رمز اشتباه است']);
        }
        exit;
    }

    // ذخیره داده‌ها (توسط کلاینت) - فقط برای داده‌های غیرحساس کاربر فعلی
    if ($action === 'save') {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $u = $input['username'];
        if (isset($data['users'][$u])) {
            // اطمینان از عدم تغییر is_admin و is_banned توسط کلاینت
            $isAdmin = $data['users'][$u]['is_admin'] ?? false;
            $isBanned = $data['users'][$u]['is_banned'] ?? false;
            $pass = $data['users'][$u]['pass']; // 📢 رمز عبور نباید از کلاینت بیاید

            // 📢 ادغام داده‌های دریافتی با داده‌های سرور
            $newData = array_merge($data['users'][$u], $input['userData']);
            
            // بازگرداندن مقادیر حساس
            $newData['is_admin'] = $isAdmin;
            $newData['is_banned'] = $isBanned;
            $newData['pass'] = $pass; 

            $data['users'][$u] = $newData;

            saveDataSafe($dataFile, $data); // ذخیره ایمن
            echo json_encode(['success'=>true]);
        }
        exit;
    }

    // لود همه داده‌ها
    if ($action === 'load') {
        $data = loadDataSafe($dataFile);
    
        // به‌روزرسانی قیمت‌ها
        $data = updatePricesIfNeeded($data);
        saveDataSafe($dataFile, $data);
    
        $username = $input['username'] ?? '';
        $now = time() * 1000;
    
        // <<< زمان چرخه ماشین استخراج — اینجا تغییر بده (60000 = ۱ دقیقه، 300000 = ۵ دقیقه، 3600000 = ۱ ساعت)
        $cycleDuration = 60000;
    
        // محاسبه و اضافه کردن خودکار داناکوین برای چرخه‌های کامل گذشته
        if ($username && isset($data['users'][$username]['miners'])) {
            $user = &$data['users'][$username];
            foreach ($user['miners'] as &$miner) {
                $cyclesPassed = floor(($now - $miner['last_collect_time']) / $cycleDuration);
    
                if ($cyclesPassed > 0) {
                    $newEarned = $cyclesPassed * ($miner['rate'] ?? 1000);
    
                    $totalCollectable = ($miner['collectable'] ?? 0) + $newEarned;
                    if ($totalCollectable > $miner['capacity']) {
                        $totalCollectable = $miner['capacity'];
                    }
                    $miner['collectable'] = $totalCollectable;
    
                    $miner['last_collect_time'] += $cyclesPassed * $cycleDuration;
                    $miner['next_collect_time'] = $miner['last_collect_time'] + $cycleDuration;
                }
            }
            unset($miner);
            saveDataSafe($dataFile, $data); // ذخیره تغییرات
        }
    
        // بقیه کد خروجی (مثل قبل نگه دار)
        $output = $data;
        $output['totalMinersBought'] = $data['totalMinersBought'] ?? 0;
        $output['totalBitcoinMinersBought'] = $data['totalBitcoinMinersBought'] ?? 0;
        $output['totalLitecoinMinersBought'] = $data['totalLitecoinMinersBought'] ?? 0;
    
        if (!isset($data['users'][$username]) || !$data['users'][$username]['is_admin']) {
            foreach ($output['users'] as $u => $userData) {
                if ($u !== $username) {
                    unset($output['users'][$u]['pass']);
                }
            }
        }
    
        if (isset($data['users'][$username])) {
            $output['currentUserStatus'] = [
                'is_banned' => $data['users'][$username]['is_banned'],
                'is_admin' => $data['users'][$username]['is_admin']
            ];
        }
    
        $output['totalMinersBought'] = $data['totalMinersBought'] ?? 0;
    
        echo json_encode($output);
        exit;
    }
    
    // --- (تغییر جدید: اضافه شدن اکشن سمت سرور برای اضافه کردن خبر)
    if ($action === 'addNews') {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $message = $input['message'] ?? '';
        $targetUser = $input['targetUser'] ?? null;
        if (!empty($message)) {
            $data = addNews($data, $message, $targetUser);
            saveDataSafe($dataFile, $data); // ذخیره ایمن
            echo json_encode(['success'=>true]);
        } else {
             echo json_encode(['success'=>false, 'msg'=>'پیام خالی است.']);
        }
        exit;
    }
    
    if ($action === 'mine_click') {
        $data = loadDataSafe($dataFile);
        $username = $input['username'] ?? '';

        if (!isset($data['users'][$username])) {
            echo json_encode(['success'=>false, 'msg'=>'کاربر یافت نشد']);
            exit;
        }

        $user = &$data['users'][$username];

        if ($user['is_banned']) {
            echo json_encode(['success'=>false, 'msg'=>'حساب شما مسدود است']);
            exit;
        }

        if ($user['is_admin']) {
            echo json_encode(['success'=>false, 'msg'=>'ادمین نمی‌تواند ماین کند']);
            exit;
        }

        $earned = $user['click_power'] ?? 5;
        $user['balance'] = ($user['balance'] ?? 0) + $earned;
        $user['clicks'] = ($user['clicks'] ?? 0) + 1;

        if (saveDataSafe($dataFile, $data)) {
            echo json_encode(['success'=>true, 'newBalance' => $user['balance'], 'earned' => $earned]);
        } else {
            echo json_encode(['success'=>false, 'msg'=>'خطا در ذخیره‌سازی']);
        }
        exit;
    }

    // === اکشن جدید: ماین دسته‌ای (برای سرعت فوق‌العاده روی موبایل) ===
       // === اکشن جدید: ماین دسته‌ای با سیستم ضد اتوکلیکر ===
       if ($action === 'mine_click_batch') {
        $data = loadDataSafe($dataFile);
        $username = $input['username'] ?? '';
        $count = (int)($input['count'] ?? 1);
        $timestamps = $input['timestamps'] ?? [];

        if (!isset($data['users'][$username])) {
            echo json_encode(['success'=>false, 'msg'=>'کاربر یافت نشد']);
            exit;
        }

        $user = &$data['users'][$username];

        if ($user['is_banned']) {
            echo json_encode(['success'=>false, 'msg'=>'حساب شما مسدود است']);
            exit;
        }

        if ($user['is_admin']) {
            echo json_encode(['success'=>false, 'msg'=>'ادمین نمی‌تواند ماین کند']);
            exit;
        }

        $now = time() * 1000;

        // چک کردن بن ماین
        if (($user['mine_ban_end'] ?? 0) > $now) {
            $hoursLeft = round(($user['mine_ban_end'] - $now) / 3600000, 1);
            echo json_encode([
                'success' => false,
                'msg' => "به دلیل اسپم کلیک تا {$hoursLeft} ساعت دیگر نمی‌تونی ماین کنی!",
                'banned' => true,
                'ban_end' => $user['mine_ban_end']
            ]);
            exit;
        }

        // حداکثر کلیک مجاز در یک ثانیه
        $MAX_CLICKS_PER_SECOND = 20;  

        $isCheating = false;

        if ($count >= 3 && count($timestamps) === $count) {
            $ts = $timestamps;
            sort($ts);
            $first = $ts[0];
            $last = $ts[$count - 1];

            if (($last - $first) < 1000 && $count > $MAX_CLICKS_PER_SECOND) {
                $isCheating = true;
            }
        }

        // اگه تقلب کرد → بن تصاعدی
        if ($isCheating) {
            $level = ($user['mine_ban_level'] ?? 0) + 1;
            $user['mine_ban_level'] = $level;
            $duration = 3600000 * pow(2, $level - 1);
            $user['mine_ban_end'] = $now + $duration;

            $banHours = pow(2, $level - 1);
            $msg = "اسپم کلیک تشخیص داده شد! تا {$banHours} ساعت دیگه نمی‌تونی ماین کنی.";

            $data = addNews($data, $msg, $username);
            saveDataSafe($dataFile, $data);

            echo json_encode([
                'success' => false,
                'msg' => $msg,
                'banned' => true,
                'ban_end' => $user['mine_ban_end']
            ]);
            exit;
        }

        // ماین عادی — همه چیز اوکی
        $earnedPerClick = $user['click_power'] ?? 5;
        $totalEarned = $earnedPerClick * $count;

        $user['balance'] = ($user['balance'] ?? 0) + $totalEarned;
        $user['totalEarned'] = ($user['totalEarned'] ?? 0) + $totalEarned;
        $user['clicks'] = ($user['clicks'] ?? 0) + $count;

        saveDataSafe($dataFile, $data);

        echo json_encode([
            'success' => true,
            'newBalance' => $user['balance']
        ]);
        exit;
    }

    // === اکشن ارتقاء ضریب کلیک ===
       // === اکشن ارتقاء ضریب کلیک (جدید) ===
    if ($action === 'upgrade_click') {
        $data = loadDataSafe($dataFile);
        $username = $input['username'] ?? '';

        if (!isset($data['users'][$username])) {
            echo json_encode(['success'=>false, 'msg'=>'کاربر یافت نشد']);
            exit;
        }

        $user = &$data['users'][$username];

        if ($user['is_banned']) {
            echo json_encode(['success'=>false, 'msg'=>'حساب شما مسدود است']);
            exit;
        }

        if ($user['is_admin']) {
            echo json_encode(['success'=>false, 'msg'=>'ادمین نمی‌تواند ارتقاء دهد']);
            exit;
        }

        $currentLevel = $user['click_level'] ?? 0;
        $upgradeCost   = $user['upgradeCost'] ?? (10000 * pow(2, $currentLevel));

        if (($user['balance'] ?? 0) < $upgradeCost) {
            echo json_encode(['success'=>false, 'msg'=>'داناکوین کافی نیست! هزینه: ' . number_format($upgradeCost)]);
            exit;
        }

        // کسر هزینه و ارتقاء
        $user['balance'] -= $upgradeCost;
        $user['click_level']   = $currentLevel + 1;
        $user['click_power']   = pow(2, $user['click_level']);
        $user['upgradeCost'] = 5000 * pow(2, $user['click_level']);

        if (saveDataSafe($dataFile, $data)) {
            echo json_encode([
                'success'    => true,
                'newBalance' => $user['balance'],
                'newPower'   => $user['click_power'],
                'newCost'    => $user['upgradeCost'],
                'newLevel'   => $user['click_level'] + 1   // نمایش از ۱ شروع بشه
            ]);
        } else {
            echo json_encode(['success'=>false, 'msg'=>'خطا در ذخیره‌سازی']);
        }
        exit;
    }

    // =================================================================================
    // توابع جدید کاربر (Client Side Actions)
    // =================================================================================
    
    // انتقال داناکوین
    if ($action === 'transfer') {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $sender = $input['sender'];
        $receiver = $input['receiver'];
        $amount = (int)$input['amount'];

        if (!isset($data['users'][$sender]) || !isset($data['users'][$receiver])) {
            echo json_encode(['success'=>false, 'msg'=>'کاربر فرستنده یا گیرنده یافت نشد.']);
            exit;
        }

        if ($data['users'][$sender]['is_banned'] || $data['users'][$receiver]['is_banned']) {
             echo json_encode(['success'=>false, 'msg'=>'عملیات برای حساب مسدود شده امکان پذیر نیست.']);
            exit;
        }

        if ($amount <= 0) {
            echo json_encode(['success'=>false, 'msg'=>'مقدار نامعتبر.']);
            exit;
        }

        if (($data['users'][$sender]['balance'] ?? 0) < $amount) {
            echo json_encode(['success'=>false, 'msg'=>'موجودی کافی نیست.']);
            exit;
        }
        
        // انجام تراکنش
        $data['users'][$sender]['balance'] -= $amount;
        $data['users'][$receiver]['balance'] += $amount;
        
        // ثبت خبر
        $data = addNews($data, "شما مبلغ **" . number_format($amount) . "** داناکوین به **$receiver** انتقال دادید.", $sender);
        $data = addNews($data, "کاربر **$sender** مبلغ **" . number_format($amount) . "** داناکوین به شما انتقال داد.", $receiver);

        saveDataSafe($dataFile, $data); // ذخیره ایمن
        echo json_encode(['success'=>true, 'msg'=>'انتقال با موفقیت انجام شد.']);
        exit;
    }

    // =================================================================================
    // توابع جدید ادمین (Admin Actions)
    // =================================================================================

    // ادمین: مسدود/باز کردن حساب
    if ($action === 'toggleBan' && isset($data['users'][$input['admin_user']]) && $data['users'][$input['admin_user']]['is_admin']) {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $targetUser = $input['targetUser'];
        $shouldBan = $input['shouldBan'];

        if ($targetUser === $adminUsername) {
            echo json_encode(['success'=>false, 'msg'=>'نمی‌توانید حساب ادمین اصلی را مسدود/باز کنید.']);
            exit;
        }

        if (isset($data['users'][$targetUser])) {
            $data['users'][$targetUser]['is_banned'] = $shouldBan;
            $data['users'][$targetUser]['ban_date'] = $shouldBan ? time() * 1000 : 0;
            $statusMsg = $shouldBan ? 'مسدود' : 'باز';
            $data = addNews($data, "حساب شما توسط ادمین **$statusMsg** شد.", $targetUser);
            saveDataSafe($dataFile, $data); // ذخیره ایمن
            echo json_encode(['success'=>true, 'msg'=>"حساب کاربری **$targetUser** با موفقیت $statusMsg شد."]);
        } else {
            echo json_encode(['success'=>false, 'msg'=>'کاربر مورد نظر یافت نشد.']);
        }
        exit;
    }
    
    // ادمین: دادن داناکوین
    if ($action === 'giveCoin' && isset($data['users'][$input['admin_user']]) && $data['users'][$input['admin_user']]['is_admin']) {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $targetUser = $input['targetUser'];
        $amount = (int)$input['amount'];

        if (!isset($data['users'][$targetUser])) {
            echo json_encode(['success'=>false, 'msg'=>'کاربر مورد نظر یافت نشد.']);
            exit;
        }
        if ($amount <= 0) {
            echo json_encode(['success'=>false, 'msg'=>'مقدار نامعتبر.']);
            exit;
        }

        $data['users'][$targetUser]['balance'] = ($data['users'][$targetUser]['balance'] ?? 0) + $amount;
        
        // ثبت خبر
        $data = addNews($data, "ادمین به شما مبلغ **" . number_format($amount) . "** داناکوین هدیه داد. 🎁", $targetUser);

        saveDataSafe($dataFile, $data); // ذخیره ایمن
        echo json_encode(['success'=>true, 'msg'=>"مبلغ **" . number_format($amount) . "** داناکوین با موفقیت به **$targetUser** داده شد."]);
        exit;
    }

    // ادمین و هلپر: حذف یک گزارش خاص (از سیستم خبرها)
if ($action === 'delete_report' && isset($data['users'][$input['admin_user']]) && 
($data['users'][$input['admin_user']]['is_admin'] || $data['users'][$input['admin_user']]['is_helper'])) {
        $data = loadDataSafe($dataFile);
        $reportTimestamp = $input['timestamp'] ?? 0;

        if ($reportTimestamp) {
            // پیدا کردن و حذف گزارش با timestamp مشخص
            $data['news'] = array_filter($data['news'], function($item) use ($reportTimestamp) {
                return $item['timestamp'] != $reportTimestamp;
            });
            // دوباره ایندکس‌ها رو مرتب کنیم (اختیاری اما تمیزتره)
            $data['news'] = array_values($data['news']);

            saveDataSafe($dataFile, $data);
            echo json_encode(['success' => true, 'msg' => 'گزارش با موفقیت حذف شد.']);
        } else {
            echo json_encode(['success' => false, 'msg' => 'گزارش یافت نشد.']);
        }
        exit;
    }
    // ادمین: ایجاد حساب هلپری
    if ($action === 'create_helper' && isset($data['users'][$input['admin_user']]) && $data['users'][$input['admin_user']]['is_admin']) {
        $data = loadDataSafe($dataFile);
        $newUsername = trim($input['username']);
        $newPass = $input['pass'];

        if (empty($newUsername) || empty($newPass)) {
            echo json_encode(['success'=>false, 'msg'=>'نام کاربری و رمز الزامی است']);
            exit;
        }
        if (isset($data['users'][$newUsername])) {
            echo json_encode(['success'=>false, 'msg'=>'این نام کاربری قبلاً وجود دارد']);
            exit;
        }
        if ($newUsername === $adminUsername) {
            echo json_encode(['success'=>false, 'msg'=>'نمی‌تونید از نام ادمین استفاده کنید']);
            exit;
        }

        $data['users'][$newUsername] = [
            'pass' => $newPass,
            'balance' => 0,
            'clicks' => 0,
            'click_level' => 0,
            'click_power' => 1,
            'upgradeCost' => 5000,
            'crypto' => ['BTC'=>0,'ETH'=>0,'BNB'=>0,'SOL'=>0,'TAO'=>0,'AAVE'=>0,'BCH'=>0,'ZEC'=>0,'XMR'=>0,'LTC'=>0,'YFI'=>0,'PAXG'=>0,'WBTC'=>0,'OKB'=>0],
            'soldiers' => 0, 'guards' => 0, 'barrackSlots' => 0, 'guardSlots' => 0,
            'is_banned' => false,
            'is_admin' => false,
            'is_helper' => true,
            'lastAttackTime' => 0,
            'mine_ban_end' => 0,
            'mine_ban_level' => 0,
            'totalEarned' => 0,
            'totalCryptoBought' => 0,
            'totalCryptoSold' => 0,
    'miners' => [],  
    'totalMinersBought' => 0
        ];

        
        saveDataSafe($dataFile, $data);
        echo json_encode(['success'=>true, 'msg'=>"حساب هلپری **{$newUsername}** با موفقیت ایجاد شد."]);
        exit;
    }

        // ادمین: ثبت اسپانسر جدید
        if ($action === 'add_sponsor' && isset($input['admin_user']) && 
        isset($data['users'][$input['admin_user']]) && 
        $data['users'][$input['admin_user']]['is_admin']) {
        
        $data = loadDataSafe($dataFile); // لود آخرین داده‌ها
        
        $name = trim($input['sponsor_name'] ?? '');
        $desc = trim($input['sponsor_desc'] ?? '');
        $link = trim($input['sponsor_link'] ?? '');
        
        if (empty($name) || empty($link)) {
            echo json_encode(['success' => false, 'msg' => 'نام و لینک الزامی است.']);
            exit;
        }
        
        // اضافه کردن اسپانسر جدید به آرایه
        $data['sponsors'][] = [
            'name' => $name,
            'description' => $desc,
            'link' => $link,
            'timestamp' => time() * 1000,
            'views' => 0  // ← جدید: شمارش بازدیدها
        ];
        
        // ذخیره ایمن
        if (saveDataSafe($dataFile, $data)) {
            echo json_encode(['success' => true, 'msg' => "اسپانسر **{$name}** با موفقیت ثبت شد."]);
        } else {
            echo json_encode(['success' => false, 'msg' => 'خطا در ذخیره‌سازی.']);
        }
        exit;
    }

    // ادمین: حذف اسپانسر
    if ($action === 'delete_sponsor' && isset($input['admin_user']) && 
        isset($data['users'][$input['admin_user']]) && 
        $data['users'][$input['admin_user']]['is_admin']) {
        
        $data = loadDataSafe($dataFile);
        $timestamp = $input['timestamp'] ?? 0;

        if ($timestamp) {
            $data['sponsors'] = array_filter($data['sponsors'], function($item) use ($timestamp) {
                return $item['timestamp'] != $timestamp;
            });
            $data['sponsors'] = array_values($data['sponsors']); // بازنشانی ایندکس‌ها

            saveDataSafe($dataFile, $data);
            echo json_encode(['success' => true, 'msg' => 'اسپانسر با موفقیت حذف شد.']);
        } else {
            echo json_encode(['success' => false, 'msg' => 'اسپانسر یافت نشد.']);
        }
        exit;
    }

    // افزایش بازدید اسپانسرها (فقط برای کاربران عادی)
    if ($action === 'increment_sponsor_views') {
        $data = loadDataSafe($dataFile);
        $username = $input['username'] ?? '';

        if (empty($username) || !isset($data['users'][$username]) || $data['users'][$username]['is_admin']) {
            echo json_encode(['success' => false]);
            exit;
        }

        $updated = false;
        foreach ($data['sponsors'] as &$sponsor) {
            $sponsor['views'] = ($sponsor['views'] ?? 0) + 1;
            $updated = true;
        }

        if ($updated) {
            saveDataSafe($dataFile, $data);
        }

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_user') {
        $data = loadDataSafe($dataFile); // لود آخرین داده
        $adminUser = $input['admin_user'] ?? '';
        $targetUser = trim($input['targetUser'] ?? '');

        // 1. چک کن که کاربر فعلی ادمین باشه
        if (!isset($data['users'][$adminUser]) || !$data['users'][$adminUser]['is_admin']) {
            echo json_encode(['success'=>false, 'msg'=>'شما اجازه انجام این عملیات را ندارید. (فقط ادمین)']);
            exit;
        }

        // 2. چک کن که نام کاربری وجود داشته باشه
        if (!isset($data['users'][$targetUser])) {
            echo json_encode(['success'=>false, 'msg'=>'کاربر مورد نظر برای حذف یافت نشد.']);
            exit;
        }
        
        // 3. چک کن که نام کاربری با نام ادمین یکی نباشه
        if ($targetUser === $adminUsername) {
            echo json_encode(['success'=>false, 'msg'=>'شما نمی‌توانید حساب کاربری ادمین اصلی را حذف کنید.']);
            exit;
        }

        // 4. تمام کلیدهای مربوط به اون کاربر رو از $users حذف کن
        unset($data['users'][$targetUser]);

        // 5. اگر در $news پیغام‌هایی از اون کاربر بود، اون‌ها رو هم حذف کن
        // حذف خبرهایی که هدف (target) آن‌ها کاربر حذف شده بوده است.
        $data['news'] = array_filter($data['news'], function($item) use ($targetUser) {
            return ($item['target'] !== $targetUser); 
        });
        
        // 6. فایل JSON رو دوباره ذخیره کن
        if (saveDataSafe($dataFile, $data)) {
            // 7. پیام موفقیت برگردونه
            echo json_encode(['success'=>true, 'msg'=>"حساب کاربری **$targetUser** با موفقیت حذف شد."]);
        } else {
            // 8. پیام خطا مناسب برگردونه
            echo json_encode(['success'=>false, 'msg'=>'خطا در ذخیره سازی داده‌ها پس از حذف.']);
        }
        exit;
    }

    // خرید ماشین استخراج
    if ($action === 'buy_miner') {
        $data = loadDataSafe($dataFile);
        $username = $input['username'] ?? '';
    
        if (!isset($data['users'][$username])) {
            echo json_encode(['success' => false, 'msg' => 'کاربر یافت نشد']);
            exit;
        }
    
        $user = &$data['users'][$username];

            // === محدودیت جدید: حداکثر ۴ ماشین داناکوین معمولی ===
    $normalMinersCount = 0;
    foreach ($user['miners'] as $miner) {
        if (!isset($miner['type']) || ($miner['type'] !== 'bitcoin' && $miner['type'] !== 'litecoin')) {
            $normalMinersCount++;
        }
    }
    if ($normalMinersCount >= 4) {
        echo json_encode([
            'success' => false,
            'msg' => 'شما نمی‌توانید بیشتر از ۴ تا از ماشین استخراج داناکوین داشته باشید.'
        ]);
        exit;
    }
    
        if ($user['is_banned']) {
            echo json_encode(['success' => false, 'msg' => 'حساب شما مسدود است']);
            exit;
        }
    
        if ($user['is_admin']) {
            echo json_encode(['success' => false, 'msg' => 'ادمین نمی‌تواند خرید کند']);
            exit;
        }
    
        $price = 250000; // قیمت جدید
    
        if (($user['balance'] ?? 0) < $price) {
            echo json_encode(['success' => false, 'msg' =>'داناکوین کافی نیست! نیاز به 250,000 داناکوین']);
            exit;
        }
    
        // کسر هزینه
        $user['balance'] -= $price;
    
        // ایجاد ماشین جدید با مقادیر اولیه جدید
        $minerId = uniqid('miner_');
        $now = time() * 1000;
        $cycleDuration = 60000; // ۱ دقیقه (می‌تونی تغییر بدی)
    
        $user['miners'][$minerId] = [
            'custom_name' => null,
            'rate_level' => 1,
            'rate' => 10000,                   // سطح ۱: ۱۰,۰۰۰ در دقیقه
            'rate_upgrade_cost' => 200000,      // هزینه ارتقا به سطح ۲
            'capacity_level' => 1,
            'capacity' => 500000,               // سطح ۱: ظرفیت ۵۰۰,۰۰۰
            'capacity_upgrade_cost' => 2000000, // هزینه ارتقا به سطح ۲
            'collectable' => 0,
            'last_collect_time' => $now,
            'next_collect_time' => $now + $cycleDuration,
            'completed' => false
        ];
    
        $data['totalMinersBought'] = ($data['totalMinersBought'] ?? 0) + 1;
    
        saveDataSafe($dataFile, $data);

        saveDataSafe($dataFile, $data);

        // محاسبه مجدد تعداد کل ماشین‌های موجود در بین همه کاربران
        $totalExistingMiners = 0;
        foreach ($data['users'] as $u) {
            if (isset($u['miners']) && is_array($u['miners'])) {
                $totalExistingMiners += count($u['miners']);
            }
        }
        $data['totalMinersBought'] = $totalExistingMiners;
        
        // <<< مهم: دوباره ذخیره کن تا totalMinersBought جدید در فایل نوشته بشه
        saveDataSafe($dataFile, $data);
        
        echo json_encode([
            'success' => true,
            'msg' => 'ماشین استخراج داناکوین با موفقیت خریداری شد!',
            'newBalance' => $user['balance'],
            'minerId' => $minerId
        ]);
        exit;
    }

        // خرید ماشین استخراج بیت‌کوین
        if ($action === 'buy_bitcoin_miner') {
            $data = loadDataSafe($dataFile);
            $username = $input['username'] ?? '';
        
            if (!isset($data['users'][$username])) {
                echo json_encode(['success' => false, 'msg' => 'کاربر یافت نشد']);
                exit;
            }
        
            $user = &$data['users'][$username];

                // === محدودیت جدید: حداکثر ۴ ماشین بیت‌کوین ===
    $bitcoinMinersCount = 0;
    foreach ($user['miners'] as $miner) {
        if (isset($miner['type']) && $miner['type'] === 'bitcoin') {
            $bitcoinMinersCount++;
        }
    }
    if ($bitcoinMinersCount >= 4) {
        echo json_encode([
            'success' => false,
            'msg' => 'شما نمی‌توانید بیشتر از ۴ تا از ماشین استخراج بیت‌کوین داشته باشید.'
        ]);
        exit;
    }
        
            if ($user['is_banned']) {
                echo json_encode(['success' => false, 'msg' => 'حساب شما مسدود است']);
                exit;
            }
        
            if ($user['is_admin']) {
                echo json_encode(['success' => false, 'msg' => 'ادمین نمی‌تواند خرید کند']);
                exit;
            }
        
            $price = 500000; // قیمت جدید
        
            if (($user['balance'] ?? 0) < $price) {
                echo json_encode(['success' => false, 'msg' => 'داناکوین کافی نیست! نیاز به 500,000 داناکوین']);
                exit;
            }
        
            // کسر هزینه
            $user['balance'] -= $price;
        
            // ایجاد ماشین بیت‌کوین جدید
            $minerId = uniqid('btc_miner_');
            $now = time() * 1000;
            $cycleDuration = 60000; // ۱ دقیقه
        
            $user['miners'][$minerId] = [
                'type' => 'bitcoin', // <<< مهم: برای تشخیص نوع ماشین
                'custom_name' => null,
                'rate_level' => 1,
                'rate' => 1,                       // سطح ۱: ۱ بیت‌کوین در دقیقه
                'rate_upgrade_cost' => 400000,
                'capacity_level' => 1,
                'capacity' => 10,                  // سطح ۱: ظرفیت ۱۰ بیت‌کوین
                'capacity_upgrade_cost' => 400000,
                'collectable' => 0,
                'last_collect_time' => $now,
                'next_collect_time' => $now + $cycleDuration,
                'completed' => false
            ];
        
        
       // بعد از ایجاد ماشین جدید و قبل از echo
saveDataSafe($dataFile, $data);

// محاسبه تعداد واقعی ماشین‌های بیت‌کوین
$totalExistingBitcoinMiners = 0;
foreach ($data['users'] as $u) {
    if (isset($u['miners']) && is_array($u['miners'])) {
        foreach ($u['miners'] as $miner) {
            if (isset($miner['type']) && $miner['type'] === 'bitcoin') {
                $totalExistingBitcoinMiners++;
            }
        }
    }
}
$data['totalBitcoinMinersBought'] = $totalExistingBitcoinMiners;

// ذخیره مجدد برای اعمال عدد جدید
saveDataSafe($dataFile, $data);

echo json_encode([
    'success' => true,
    'msg' => 'ماشین استخراج بیت‌کوین با موفقیت خریداری شد!',
    'newBalance' => $user['balance'],
    'minerId' => $minerId
]);
            exit;
        }

                // خرید ماشین استخراج لایت‌کوین
                if ($action === 'buy_litecoin_miner') {
                    $data = loadDataSafe($dataFile);
                    $username = $input['username'] ?? '';
                
                    if (!isset($data['users'][$username])) {
                        echo json_encode(['success' => false, 'msg' => 'کاربر یافت نشد']);
                        exit;
                    }
                
                    $user = &$data['users'][$username];

                        // === محدودیت جدید: حداکثر ۴ ماشین لایت‌کوین ===
    $litecoinMinersCount = 0;
    foreach ($user['miners'] as $miner) {
        if (isset($miner['type']) && $miner['type'] === 'litecoin') {
            $litecoinMinersCount++;
        }
    }
    if ($litecoinMinersCount >= 4) {
        echo json_encode([
            'success' => false,
            'msg' => 'شما نمی‌توانید بیشتر از ۴ تا از ماشین استخراج لایت‌کوین داشته باشید.'
        ]);
        exit;
    }
                
                    if ($user['is_banned']) {
                        echo json_encode(['success' => false, 'msg' => 'حساب شما مسدود است']);
                        exit;
                    }
                
                    if ($user['is_admin']) {
                        echo json_encode(['success' => false, 'msg' => 'ادمین نمی‌تواند خرید کند']);
                        exit;
                    }
                
                    $price = 2000; // قیمت خرید
                
                    if (($user['balance'] ?? 0) < $price) {
                        echo json_encode(['success' => false, 'msg' => 'داناکوین کافی نیست! نیاز به 2,000 داناکوین']);
                        exit;
                    }
                
                    // کسر هزینه
                    $user['balance'] -= $price;
                
                    // ایجاد ماشین لایت‌کوین جدید
                    $minerId = uniqid('ltc_miner_');
                    $now = time() * 1000;
                    $cycleDuration = 60000; // ۱ دقیقه
                
                   // در بخش buy_litecoin_miner
$user['miners'][$minerId] = [
    'type' => 'litecoin',
    'custom_name' => null,
    'rate_level' => 1,
    'rate' => 1,
    'rate_upgrade_cost' => 10000, // تغییر به ۱۵۰۰
    'capacity_level' => 1,
    'capacity' => 10,
    'capacity_upgrade_cost' => 10000, // تغییر به ۱۵۰۰
    'collectable' => 0,
    'last_collect_time' => $now,
    'next_collect_time' => $now + $cycleDuration,
    'completed' => false
];
                
                    // محاسبه تعداد واقعی ماشین‌های لایت‌کوین
                    $totalExistingLitecoinMiners = 0;
                    foreach ($data['users'] as $u) {
                        if (isset($u['miners']) && is_array($u['miners'])) {
                            foreach ($u['miners'] as $miner) {
                                if (isset($miner['type']) && $miner['type'] === 'litecoin') {
                                    $totalExistingLitecoinMiners++;
                                }
                            }
                        }
                    }
                    $data['totalLitecoinMinersBought'] = $totalExistingLitecoinMiners;
                
                    // ذخیره تغییرات
                    saveDataSafe($dataFile, $data);
                
                    echo json_encode([
                        'success' => true,
                        'msg' => 'ماشین استخراج لایت‌کوین با موفقیت خریداری شد!',
                        'newBalance' => $user['balance'],
                        'minerId' => $minerId
                    ]);
                    exit;
                }

        // برداشت داناکوین از ماشین استخراج
        if ($action === 'collect_miner') {
            $data = loadDataSafe($dataFile);
            $username = $input['username'] ?? '';
            $minerId = $input['minerId'] ?? '';
            $now = time() * 1000;
            $cycleDuration = 60000;
        
            if (!isset($data['users'][$username])) {
                echo json_encode(['success' => false, 'msg' => 'کاربر یافت نشد']);
                exit;
            }
        
            $user = &$data['users'][$username];
            if ($user['is_banned']) {
                echo json_encode(['success' => false, 'msg' => 'حساب شما مسدود است']);
                exit;
            }
            if ($user['is_admin']) {
                echo json_encode(['success' => false, 'msg' => 'ادمین نمی‌تواند برداشت کند']);
                exit;
            }
        
            if (!isset($user['miners'][$minerId])) {
                echo json_encode(['success' => false, 'msg' => 'ماشین استخراج یافت نشد']);
                exit;
            }
        
            $miner = &$user['miners'][$minerId];
        
            // <<< جدید: دقیقاً مثل load، چرخه‌های کامل گذشته را اضافه کن
            $cyclesPassed = floor(($now - $miner['last_collect_time']) / $cycleDuration);
            if ($cyclesPassed > 0) {
                $newEarned = $cyclesPassed * ($miner['rate'] ?? 10000);
                $totalCollectable = ($miner['collectable'] ?? 0) + $newEarned;
                if ($totalCollectable > $miner['capacity']) {
                    $totalCollectable = $miner['capacity'];
                }
                $miner['collectable'] = $totalCollectable;
                $miner['last_collect_time'] += $cyclesPassed * $cycleDuration;
                $miner['next_collect_time'] = $miner['last_collect_time'] + $cycleDuration;
                saveDataSafe($dataFile, $data); // ذخیره تغییرات چرخه‌ها
            }
        
            $collectableAmount = $miner['collectable'] ?? 0;
        
            if ($collectableAmount <= 0) {
                echo json_encode(['success' => true, 'msg' => 'هیچ داناکوینی برای برداشت وجود ندارد.', 'amount' => 0, 'newBalance' => $user['balance']]);
                exit;
            }
        
            $user['balance'] += $collectableAmount;
            $miner['collectable'] = 0;
            $miner['last_collect_time'] = $now;
            $miner['next_collect_time'] = $now + $cycleDuration;
        
            saveDataSafe($dataFile, $data);
        
            echo json_encode([
                'success' => true,
                'msg' => 'برداشت با موفقیت انجام شد!',
                'amount' => $collectableAmount,
                'newBalance' => $user['balance']
            ]);
            exit;
        }

            // ارتقا rate ماشین استخراج
   // ارتقا دریافت در دقیقه
if ($action === 'upgrade_miner_rate') {
    $data = loadDataSafe($dataFile);
    $username = $input['username'] ?? '';
    $minerId = $input['minerId'] ?? '';

    if (!isset($data['users'][$username]['miners'][$minerId])) {
        echo json_encode(['success' => false, 'msg' => 'ماشین یافت نشد']);
        exit;
    }

    $user = &$data['users'][$username];
    $miner = &$user['miners'][$minerId];

    if ($miner['rate_level'] >= 20) {
        echo json_encode(['success' => false, 'msg' => 'دریافت در دقیقه به حداکثر سطح (۲۰) رسیده است!']);
        exit;
    }

    $cost = $miner['rate_upgrade_cost'];

    if (($user['balance'] ?? 0) < $cost) {
        echo json_encode(['success' => false, 'msg' => "داناکوین کافی نیست! هزینه: " . number_format($cost)]);
        exit;
    }

    // کسر هزینه و ارتقا
    $user['balance'] -= $cost;
    $miner['rate_level'] += 1;
    $miner['rate'] *= 2; // دو برابر شدن دریافت
    $miner['rate_upgrade_cost'] *= 2; // دو برابر شدن هزینه بعدی

    // چک کامل شدن
    if ($miner['rate_level'] >= 20 && $miner['capacity_level'] >= 20) {
        $miner['completed'] = true;
    }

    saveDataSafe($dataFile, $data);

    echo json_encode([
        'success' => true,
        'msg' => "دریافت در دقیقه به سطح {$miner['rate_level']} ارتقا یافت!",
        'newRateLevel' => $miner['rate_level'],
        'newRate' => $miner['rate'],
        'newRateCost' => $miner['rate_upgrade_cost'],
        'newBalance' => $user['balance'],
        'completed' => $miner['completed']
    ]);
    exit;
}

// ارتقا ظرفیت مخزن
if ($action === 'upgrade_miner_capacity') {
    $data = loadDataSafe($dataFile);
    $username = $input['username'] ?? '';
    $minerId = $input['minerId'] ?? '';

    if (!isset($data['users'][$username]['miners'][$minerId])) {
        echo json_encode(['success' => false, 'msg' => 'ماشین یافت نشد']);
        exit;
    }

    $user = &$data['users'][$username];
    $miner = &$user['miners'][$minerId];

    if ($miner['capacity_level'] >= 20) {
        echo json_encode(['success' => false, 'msg' => 'ظرفیت مخزن به حداکثر سطح (۲۰) رسیده است!']);
        exit;
    }

    $cost = $miner['capacity_upgrade_cost'];

    if (($user['balance'] ?? 0) < $cost) {
        echo json_encode(['success' => false, 'msg' => "داناکوین کافی نیست! هزینه: " . number_format($cost)]);
        exit;
    }

    // کسر هزینه و ارتقا
    $user['balance'] -= $cost;
    $miner['capacity_level'] += 1;
    $miner['capacity'] *= 2; // دو برابر شدن ظرفیت
    $miner['capacity_upgrade_cost'] *= 2; // دو برابر شدن هزینه بعدی

    // چک کامل شدن
    if ($miner['rate_level'] >= 20 && $miner['capacity_level'] >= 20) {
        $miner['completed'] = true;
    }

    saveDataSafe($dataFile, $data);

    echo json_encode([
        'success' => true,
        'msg' => "ظرفیت مخزن به سطح {$miner['capacity_level']} ارتقا یافت!",
        'newCapacityLevel' => $miner['capacity_level'],
        'newCapacity' => $miner['capacity'],
        'newCapacityCost' => $miner['capacity_upgrade_cost'],
        'newBalance' => $user['balance'],
        'completed' => $miner['completed']
    ]);
    exit;
}

    // ارتقا capacity ماشین استخراج
    if ($action === 'upgrade_miner_capacity') {
        $data = loadDataSafe($dataFile);
        $username = $input['username'] ?? '';
        $minerId = $input['minerId'] ?? '';

        if (!isset($data['users'][$username])) {
            echo json_encode(['success' => false, 'msg' => 'کاربر یافت نشد']);
            exit;
        }

        $user = &$data['users'][$username];
        if ($user['is_banned']) {
            echo json_encode(['success' => false, 'msg' => 'حساب شما مسدود است']);
            exit;
        }
        if ($user['is_admin']) {
            echo json_encode(['success' => false, 'msg' => 'ادمین نمی‌تواند ارتقا دهد']);
            exit;
        }

        if (!isset($user['miners'][$minerId])) {
            echo json_encode(['success' => false, 'msg' => 'ماشین استخراج یافت نشد']);
            exit;
        }

        $miner = &$user['miners'][$minerId];

        if ($miner['capacity_level'] >= 20) {
            echo json_encode(['success' => false, 'msg' => 'این بخش به حداکثر سطح (۲۰) رسیده است!']);
            exit;
        }

        $nextLevel = $miner['capacity_level'] + 1;
        $cost = 15000 * $nextLevel; // هزینه بر اساس سطح بعدی

        if (($user['balance'] ?? 0) < $cost) {
            echo json_encode(['success' => false, 'msg' => "داناکوین کافی نیست! هزینه: " . number_format($cost)]);
            exit;
        }

        // کسر هزینه
        $user['balance'] -= $cost;

        // ارتقا
        $miner['capacity_level'] = $nextLevel;
        $miner['capacity'] += 5000; // هر سطح ۵۰۰۰ اضافه می‌شه

        // چک کامل شدن
        if ($miner['rate_level'] >= 20 && $miner['capacity_level'] >= 20) {
            $miner['completed'] = true;
        }

        saveDataSafe($dataFile, $data);

        echo json_encode([
            'success' => true,
            'msg' => "ارتقا ظرفیت مخزن با موفقیت انجام شد!",
            'newCapacityLevel' => $nextLevel,
            'newCapacity' => $miner['capacity'],
            'newBalance' => $user['balance'],
            'completed' => $miner['completed'] ?? false
        ]);
        exit;
    }

        // انتخاب اسم دلخواه برای ماشین
        if ($action === 'set_miner_name') {
            $data = loadDataSafe($dataFile);
            $username = $input['username'] ?? '';
            $minerId = $input['minerId'] ?? '';
            $newName = trim($input['newName'] ?? '');
        
            if (!isset($data['users'][$username])) {
                echo json_encode(['success' => false, 'msg' => 'کاربر یافت نشد']);
                exit;
            }
        
            $user = &$data['users'][$username];
            if ($user['is_banned']) {
                echo json_encode(['success' => false, 'msg' => 'حساب شما مسدود است']);
                exit;
            }
            if ($user['is_admin']) {
                echo json_encode(['success' => false, 'msg' => 'ادمین نمی‌تواند اسم انتخاب کند']);
                exit;
            }
        
            if (!isset($user['miners'][$minerId])) {
                echo json_encode(['success' => false, 'msg' => 'ماشین استخراج یافت نشد']);
                exit;
            }
        
            // محدودیت طول اسم
            if (mb_strlen($newName) < 1 || mb_strlen($newName) > 20) {
                echo json_encode(['success' => false, 'msg' => 'اسم باید بین ۱ تا ۲۰ کاراکتر باشد']);
                exit;
            }
        
            // چک کردن منحصر به فرد بودن اسم در کل سایت
            foreach ($data['users'] as $u) {
                if (isset($u['miners'])) {
                    foreach ($u['miners'] as $m) {
                        if (isset($m['custom_name']) && $m['custom_name'] === $newName) {
                            // حتی اگر خود کاربر باشه و ماشین دیگه‌ای داشته باشه با این اسم
                            if ($m['custom_name'] === $newName && $minerId !== array_search($m, $u['miners'])) {
                                echo json_encode(['success' => false, 'msg' => 'این اسم قبلاً توسط ماشین دیگری در سایت استفاده شده است!']);
                                exit;
                            }
                            echo json_encode(['success' => false, 'msg' => 'این اسم قبلاً توسط ماشین دیگری در سایت استفاده شده است!']);
                            exit;
                        }
                    }
                }
            }
        
            // اگر همه چک‌ها اوکی بود → اسم رو ست کن
            $user['miners'][$minerId]['custom_name'] = $newName;
        
            saveDataSafe($dataFile, $data);
        
            echo json_encode([
                'success' => true,
                'msg' => "اسم ماشین با موفقیت به «{$newName}» تغییر کرد!",
                'newName' => $newName
            ]);
            exit;
        }

                // فروش ماشین به سایت
                if ($action === 'sell_miner') {
                    $data = loadDataSafe($dataFile);
                    $username = $input['username'] ?? '';
                    $minerId = $input['minerId'] ?? '';
                
                    if (!isset($data['users'][$username]['miners'][$minerId])) {
                        echo json_encode(['success' => false, 'msg' => 'ماشین یافت نشد']);
                        exit;
                    }
                
                    $miner = $data['users'][$username]['miners'][$minerId];
                    $type = $miner['type'] ?? 'danacoin';
                
                    // قیمت پایه
                    $basePrice = ($type === 'bitcoin') ? 250000 : (($type === 'litecoin') ? 1000 : 125000);
                
                    // چک کامل شدن
                    $isComplete = ($miner['rate_level'] >= 20) && ($miner['capacity_level'] >= 20);
                    $sellPrice = $isComplete ? $basePrice * 5 : $basePrice;
                
                    // اضافه کردن به موجودی و حذف ماشین
                    $data['users'][$username]['balance'] += $sellPrice;
                    unset($data['users'][$username]['miners'][$minerId]);
                
                    saveDataSafe($dataFile, $data);
                
                    echo json_encode([
                        'success' => true,
                        'msg' => "ماشین با موفقیت به قیمت " . number_format($sellPrice) . " داناکوین فروخته شد!",
                        'earned' => $sellPrice,
                        'newBalance' => $data['users'][$username]['balance']
                    ]);
                    exit;
                }


?>





<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<title>داناکوین - بازی استراتژیک آنلاین</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
<style>
    * { margin:0; padding:0; box-sizing:border-box; font-family: 'Vazirmatn', sans-serif; }
    body { background: linear-gradient(135deg, #0f0c29, #302b63, #24243e); color:#fff; min-height:100vh; }
    .container { max-width: 900px; margin:0 auto; padding:20px; }
    header { position:fixed; top:10px; right:20px; z-index:999; background:rgba(0,0,0,0.5); padding:10px; border-radius:15px; display:flex; gap:10px;}
    button { cursor:pointer; outline: none; -webkit-tap-highlight-color: transparent; } /* 📢 اصلاح برای حذف هاله آبی در موبایل */
    .btn { padding:12px 25px; background:#ff9800; border:none; border-radius:16px; color:#000; font-weight:bold; transition:0.3s; }
    .btn:hover { background:#ffb74d; transform:scale(1.05); }
    /* تغییرات استایل دکمه‌های خرید و فروش رمز ارز */
    .btn.buy-btn { background:#4CAF50; color:#fff; } 
    .btn.buy-btn:hover { background:#66BB6A; } 
    .btn.sell-btn { background:#f44336; color:#fff; }
    .btn.sell-btn:hover { background:#E57373; }
    .btn.chart-btn { background:#87CEEB; color:#fff; } /* آبی آسمونی */
.btn.chart-btn:hover { background:#ADD8E6; }
    
    .btn-big { user-select: none; -webkit-touch-callout: none; -webkit-tap-highlight-color: transparent; width:220px; height:220px; border-radius:50%; font-size:32px; background:radial-gradient(#ff512f, #dd2476); box-shadow:0 0 30px #ff512f88; }
    .btn-big:hover { box-shadow:0 0 50px #ff512f; }
    .section { display:none; min-height:100vh; padding-top:80px; text-align:center; }
    .active { display:block; }
    h1 { font-size:48px; margin-bottom:20px; background:linear-gradient(45deg,#ff9a9e,#fad0c4); -webkit-background-clip:text; -webkit-text-fill-color:transparent; }
    .particle { position:absolute; pointer-events:none; font-size:30px; animation: float 2s ease-out forwards; }
    @keyframes float { 0%{opacity:1; transform:translateY(0);} 100%{opacity:0; transform:translateY(-150px);} }
    .modal { position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.9); display:flex; align-items:center; justify-content:center; z-index:1000; display:none; }
    .modal-content { background:#1e1e2e; padding:30px; border-radius:20px; max-width:500px; text-align:center; }
    .leaderboard table, .userlist table, .news-list table { width:100%; margin:30px 0; border-collapse:collapse; text-align:right;}
    .leaderboard td, .leaderboard th, .userlist td, .userlist th, .news-list td, .news-list th { padding:12px; border-bottom:1px solid #444; }
    .news-list th { text-align:right;}
    
    /* تغییرات استایل جدول صرافی (طراحی مجدد کامل برای موبایل و دسکتاپ) - تغییر ۲ */
    .crypto-table { 
        width: 100%; /* ریسپانسیو */
        max-width: 500px;
        margin: 30px auto; 
        border-collapse: separate; 
        border-spacing: 0 15px; /* فاصله بیشتر بین ردیف‌ها */
    }
        
        /* 🔔 تبدیل ردیف جدول به کارت */
        .crypto-table thead {
            display: none; /* هدر جدول پنهان شود */
        }
        
        .crypto-table tr {
            display: block;
            margin-bottom: 20px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 8px 25px rgba(255, 152, 0, 0.2);            
            background: #1e1e2e;
        }
    
        .crypto-table td {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 15px;
            border-bottom: 1px solid #444;
            background: transparent;
            border-radius: 0;
        }
        
        .crypto-table td:before {
            content: attr(data-label);
            font-weight: 400;
            color: #ccc;
            margin-left: 10px;
            font-size: 14px;
        }
    
        .crypto-table td:last-child {
            display: flex; /* برای چیدمان دکمه‌ها کنار هم */
            gap: 5px; /* فاصله بین دکمه‌ها */
            justify-content: space-around;
            text-align: center;
            border-bottom: none;
            padding: 20px 15px;
        }
        
        /* دکمه‌های خرید و فروش بزرگ و مربعی */
        .crypto-table td button {
            width: 48% !important; 
            height: 60px; /* بزرگ و مربعی */
            padding: 0;
            margin: 0;
            font-size: 18px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .crypto-table td:nth-child(2) { color: #4CAF50; }
        .crypto-table td:nth-child(3) { color: #ff9800; }
        .crypto-table td:first-child { font-size: 24px; }
        
        .crypto-table input[type="number"] {
            width: 50% !important; /* کوچک کردن input برای موبایل */
        }
    
    
    

    
    /* پایان تغییرات ۲ */
    
    input[type="text"], input[type="number"], input[type="password"] { color: #000; padding:12px; margin:15px; border-radius:10px; border:none; }
    .admin-action { padding: 20px; background: #333; margin: 15px auto; border-radius: 10px; max-width: 400px; }
    /* 📢 استایل جدید برای بخش حذف حساب ادمین */
    .admin-delete-action {
        background-color: #2b1b2e; /* پس‌زمینه تیره */
        border: 2px solid #e91e63; /* حاشیه صورتی */
        padding: 30px;
        margin: 20px auto;
        border-radius: 15px;
        max-width: 450px;
        box-shadow: 0 0 20px #e91e6355;
    }
    .btn-delete-user {
        background: #e91e63; /* دکمه قرمز/صورتی تیره */
        color: white;
        font-weight: bold;
        padding: 15px 30px;
        margin-top: 20px;
    }
    .btn-delete-user:hover {
        background: #f06292;
    }
    .admin-delete-action p {
        color: #ff9800; /* رنگ هشدار نارنجی */
        font-weight: bold;
        margin-bottom: 25px;
        font-size: 16px;
    }
    /* 🔔 استایل‌های زنگوله جدید */
    #newsBell {
        position: fixed;
        top: 20px;
        left: 20px;
        z-index: 1000;
        cursor: pointer;
        display: none; /* 🚫 پیش‌فرض: عدم نمایش. توسط JS مدیریت می‌شود */
        align-items: center;
        justify-content: center;
        width: 40px;
        height: 40px;
        background: #302b63;
        border-radius: 50%;
        box-shadow: 0 0 10px rgba(255, 152, 0, 0.5);
    }

    #newsBell:hover {
        transform: scale(1.1);
        transition: 0.2s;
    }
    
    #newsBadge {
        position: absolute;
        top: -5px;
        right: -5px;
        background: #ff512f;
        color: white;
        border-radius: 50%;
        padding: 3px 6px;
        font-size: 12px;
        font-weight: bold;
        min-width: 20px;
        text-align: center;
        box-shadow: 0 0 5px #ff512f;
    }
    
    /* 🔔 انیمیشن تکان خوردن زنگوله */
    .shake-bell {
        animation: shake 0.82s cubic-bezier(.36,.07,.19,.97) both infinite;
        transform: translate3d(0, 0, 0);
        backface-visibility: hidden;
        perspective: 1000px;
    }
    
    @keyframes shake {
      10%, 90% { transform: translate3d(-1px, 0, 0); }
      20%, 80% { transform: translate3d(+2px, 0, 0); }
      30%, 50%, 70% { transform: translate3d(-4px, 0, 0); }
      40%, 60% { transform: translate3d(+4px, 0, 0); }
    }
    
    .buy-action { 
        display: flex; 
        justify-content: center; 
        align-items: center; 
        gap: 10px; 
        margin-bottom: 20px; 
    }
    
    .buy-action input {
        width: 80px;
        margin: 0;
        padding: 8px;
        text-align: center;
    }
    
    .buy-action .btn {
        padding: 8px 15px;
        margin: 0;
    }
    
    .barracks-info p {
        font-size: 18px;
        margin: 10px 0;
        text-align: center;
    }
    
    .dashboard-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr); /* ← تغییر به ۳ ستون */
    gap: 25px;
    margin-top: 50px;
    max-width: 900px; /* عرض بیشتر برای ۳ ستون */
    margin-left: auto;
    margin-right: auto;
    justify-content: center;
    padding: 0 20px;
}

.dashboard-grid .dashboard-mine-btn {
    width: 100%;
    max-width: 180px; /* اندازه ثابت برای هر دکمه تا منظم بمونن */
    margin: 0 auto;
}
    .dashboard-grid .btn {
        width: 100%; 
    }
    
    /* افکت لرزش دکمه موقع کلیک */
@keyframes clickPulse {
    0% { transform: scale(1); box-shadow: 0 0 30px #ff512f88; }
    50% { transform: scale(0.92); box-shadow: 0 0 60px #ff512f; }
    100% { transform: scale(1); box-shadow: 0 0 30px #ff512f88; }
}

.pulse {
    animation: clickPulse 0.4s ease-out;
}

/* ذرات زیباتر با گرادیان و سایه */
/* ذرات سبز نئونی حرفه‌ای */
.particle {
    position: fixed;
    pointer-events: none;
    font-weight: bold;
    z-index: 9999;
    user-select: none;
    white-space: nowrap;
    
    /* رنگ سبز نئونی با گرادیان جذاب */
    background: linear-gradient(135deg, #39ff14, #00ff9d, #00ff6a);
    -webkit-background-clip: text;
    background-clip: text;
    -webkit-text-fill-color: transparent;
    
    /* سایه درخشان سبز */
    text-shadow: 
        0 0 10px #39ff14,
        0 0 20px #39ff14,
        0 0 40px #39ff14,
        0 0 60px #00ff9d;
    
    animation: floatUp 1.8s ease-out forwards;
}

@keyframes floatUp {
    to {
        transform: translate(var(--offset-x, 0px), var(--offset-y, -180px)) scale(0.6);
        opacity: 0;
    }
}
/* نوار جستجوی صرافی - طراحی حرفه‌ای */
.crypto-search-container {
    position: relative;
    max-width: 500px;
    margin: 20px auto;
    padding: 0 15px;
}

#cryptoSearch {
    width: 100%;
    padding: 16px 50px 16px 20px;
    font-size: 18px;
    border-radius: 50px;
    border: 2px solid #ff9800;
    background: #1e1e2e;
    color: #fff;
    outline: none;
    transition: all 0.3s;
    box-shadow: 0 4px 15px rgba(255, 152, 0, 0.2);
}

#cryptoSearch:focus {
    border-color: #ffb74d;
    box-shadow: 0 0 20px rgba(255, 183, 77, 0.5);
    transform: scale(1.02);
}

.search-icon {
    position: absolute;
    left: 25px;
    top: 50%;
    transform: translateY(-50%);
    width: 28px;
    height: 28px;
    color: #ff9800;
    pointer-events: none;
}

/* وقتی چیزی پیدا نشد */
.no-results {
    text-align: center;
    padding: 40px;
    color: #ff9800;
    font-size: 20px;
    background: #ffffff11;
    border-radius: 15px;
    margin: 20px auto;
    max-width: 500px;
}

.guide-accordion { max-width:800px; margin:0 auto; }
            .guide-btn {
                background: linear-gradient(45deg, #ff9a9e, #fad0c4);
                color: #000;
                padding: 18px;
                width: 100%;
                text-align: right;
                border: none;
                outline: none;
                font-size: 18px;
                font-weight: bold;
                cursor: pointer;
                margin-top: 10px;
                border-radius: 15px;
                transition: 0.3s;
                box-shadow: 0 4px 10px rgba(0,0,0,0.3);
            }
            .guide-btn:hover { background:#ffb74d; transform:scale(1.02); }
            .guide-btn.active { background:#ff9800; border-bottom-left-radius:0; border-bottom-right-radius:0; }
            .guide-panel {
                padding: 20px;
                background:#ffffff11;
                border-radius:0 0 15px 15px;
                margin-bottom:15px;
                display:none;
                text-align:right;
                direction:rtl;
                line-height:1.8;
                border:1px solid #444;
                border-top:none;
            }

/* استایل کارت محصولات - دقیقاً شبیه کارت‌های کریپتو */
.product-card {
    background: #1e1e2e;
    border-radius: 15px;
    padding: 20px;
    margin: 20px auto;
    max-width: 500px;
    box-shadow: 0 8px 25px rgba(255, 152, 0, 0.2);
    text-align: right;
    direction: rtl;
}

.product-card h2 {
    font-size: 24px;
    margin-bottom: 15px;
    color: #ff9800;
}

.product-card p {
    margin: 12px 0;
    font-size: 16px;
}

.product-card strong {
    color: #ccc;
}

.miner-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    margin-top: 20px;
}

.miner-buttons .btn {
    width: 48%;
    height: 60px;
    font-size: 16px;
    border-radius: 15px;
}

.my-miner-card {
    border: 2px solid #ff9800;
}

.product-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 15px;
    border-bottom: 1px solid #444;
    font-size: 16px;
}
.product-info-row strong {
    color: #ccc;
    font-weight: normal;
}
.product-info-row .value {
    color: #ff9800;
    font-weight: bold;
}

/* کارت محصولات مثل کارت‌های کریپتو — کاملاً responsive برای موبایل */
.product-card {
    display: block;
    margin-bottom: 20px;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 8px 25px rgba(255, 152, 0, 0.2);
    background: #1e1e2e;
    max-width: 500px;
    margin-left: auto;
    margin-right: auto;
}

.product-card h2 {
    text-align: center;
    padding: 20px 15px 10px;
    font-size: 24px;
    color: #ff9800;
    margin: 0;
}

.product-info-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 20px;
    border-bottom: 1px solid #444;
    font-size: 16px;
}

.product-info-row strong {
    color: #ccc;
    font-weight: normal;
    font-size: 15px;
}

.product-info-row .value {
    color: #ff9800;
    font-weight: bold;
    text-align: left;
    flex: 1;
    margin-right: 10px;
    word-break: break-word;
}

.miner-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    padding: 20px;
    justify-content: center;
}

.miner-buttons .btn {
    flex: 1 1 45%;
    min-width: 130px;
    height: 55px;
    font-size: 15px;
    border-radius: 12px;
    padding: 0 10px;
}

/* برای موبایل — دکمه‌ها کوچیک‌تر و مرتب‌تر */
@media (max-width: 480px) {
    .miner-buttons .btn {
        height: 50px;
        font-size: 14px;
    }
    .product-info-row {
        padding: 12px 15px;
        font-size: 15px;
    }
    .product-card h2 {
        font-size: 22px;
        padding: 15px 10px 5px;
    }
}

.input-wrapper {
    position: relative;
    width: 90%;
    margin: 15px auto; /* فاصله عمودی یکسان و تراز وسط */
}

.input-wrapper input {
    width: 100%;
    padding: 12px 40px 12px 12px; /* فضای کافی برای آیکون در سمت چپ */
    box-sizing: border-box;
    border-radius: 8px; /* اختیاری: گوشه‌های گرد یکسان */
    border: 1px solid #ccc; /* اختیاری: ظاهر بهتر */
}

.eye-icon {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    font-size: 20px;
    user-select: none;
}

/* Loading Spinner - اضافه شده برای حذف فلش welcome */
#loadingOverlay {
    display: flex;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.9); /* پس‌زمینه تیره نیمه‌شفاف */
    justify-content: center;
    align-items: center;
    flex-direction: column;
    z-index: 9999; /* بالای همه چیز */
    color: white;
    font-size: 24px;
    text-align: center;
}

.loader {
    border: 16px solid #333; /* خاکستری تیره */
    border-top: 16px solid #ff9800; /* نارنجی مثل تم سایتت */
    border-radius: 50%;
    width: 120px;
    height: 120px;
    animation: spin 1.5s linear infinite;
    margin-bottom: 30px;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

#loadingOverlay.hidden {
    display: none !important;
}

</style>
</head>
<body>

<div id="newsBell" onclick="showSection('news');" >
    <svg fill="#ff9800" xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24"><path d="M12 22c1.1 0 2-.9 2-2h-4c0 1.1.9 2 2 2zm6-6v-5c0-3.07-1.63-5.64-4.5-6.32V4c0-.83-.67-1.5-1.5-1.5s-1.5.67-1.5 1.5v.68C7.64 5.36 6 7.93 6 11v5l-2 2v1h16v-1l-2-2z"/></svg>
    <span id="newsBadge" style="display:none;">0</span>
</div>

<header>
    <button class="btn" id="logoutBtn" onclick="logout()" style="background:#f44336; color:#fff; display:none;">خروج</button>
    <button class="btn" id="dashboardBtn" onclick="showSection(getMainDashboard())" style="display:none;">داشبورد</button>
    <button class="btn" id="backBtn" onclick="goBack()" style="background:#9c27b0; color:#fff; display:none;">بازگشت</button>
</header>

<div class="container">

<div id="welcome" class="section">
    <h1>به داناکوین خوش آمدید!</h1>
    <p style="margin-bottom:30px;">با اقتصاد و سیاست رتبه اول شو!</p>

    <div id="login" style="background:#ffffff11; padding:30px; border-radius:15px; max-width:400px; margin:0 auto;">
        <h2 style="margin-bottom:20px;">ورود یا ثبت نام</h2>
        
        <!-- فیلد نام کاربری -->
        <div class="input-wrapper">
            <input type="text" id="regUsername" placeholder="نام کاربری (حداقل ۳ کاراکتر)">
        </div>
        
        <!-- فیلد رمز عبور با آیکون چشم -->
        <div class="input-wrapper">
            <input type="password" id="regPass" placeholder="رمز عبور (حداقل ۴ رقم)">
            <span id="toggleEye" onclick="togglePassword()" class="eye-icon">👁️</span>
        </div>
        
        <div style="margin: 30px 0 20px; text-align: center; direction: rtl;">
    <input type="checkbox" id="agreeRules" style="width: 18px; height: 18px; margin-left: 8px;">
    <label for="agreeRules" style="font-size: 16px; color: #fff;">
        من <a href="rules.php" style="color: #ff9800; text-decoration: underline;" onclick="event.stopPropagation();">قوانین و مقررات</a> را خوانده و قبول دارم
    </label>
</div>

        <button class="btn" onclick="login()">ورود</button>
        <button class="btn" style="background:#00bcd4;" onclick="register()">ثبت نام</button>
    </div>
</div>

    <div id="dashboard" class="section">
        <h1>داشبورد <span id="usernameDisplay"></span></h1>
        <p style="font-size:30px;">داناکوین: <span id="balance">0</span></p>
        <p style="font-size:18px; margin-bottom: 50px;">ارزش کل دارایی (تقریبی): <span id="totalBalance">0</span> داناکوین</p>

        <div class="dashboard-grid">

        <div class="dashboard-mine-btn" onclick="showSection('guide')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <path d="M10 9C10 8.60444 10.1173 8.21776 10.3371 7.88886C10.5568 7.55996 10.8692 7.30362 11.2346 7.15224C11.6001 7.00087 12.0022 6.96126 12.3902 7.03843C12.7781 7.1156 13.1345 7.30608 13.4142 7.58579C13.6939 7.86549 13.8844 8.22186 13.9616 8.60982C14.0387 8.99778 13.9991 9.39992 13.8478 9.76537C13.6964 10.1308 13.44 10.4432 13.1111 10.6629C12.7822 10.8827 12.3956 11 12 11V12M14.25 19L12.8 20.9333C12.4 21.4667 11.6 21.4667 11.2 20.9333L9.75 19H7C4.79086 19 3 17.2091 3 15V7C3 4.79086 4.79086 3 7 3H17C19.2091 3 21 4.79086 21 7V15C21 17.2091 19.2091 19 17 19H14.25Z" stroke="#fff700" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
            <circle cx="12" cy="15" r="1" fill="#fff700"></circle>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        راهنمای کامل سایت
    </p>
</div>

        <div class="dashboard-mine-btn" onclick="showSection('mine')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 36 36" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g transform="rotate(45 18 18)">
            <path d="M27.6 14.2426092L27.6 15.6852683C27.6 16.2158254 27.1970563 16.6459269 26.7 16.6459269L21.3 16.6459269C20.8029437 16.6459269 20.4 16.2158254 20.4 15.6852683L20.4 14.2426092C11.4042134 14.9678356 6 18.0459827 6 16.6339865 6 15.1676293 14.906129 9.9124942 22.2 9.07386223L22.2 8.96065854C22.2 8.43010148 22.6029437 8 23.1 8L24.9 8C25.3970563 8 25.8 8.43010148 25.8 8.96065854L25.8 9.0732069C33.093871 9.9124942 42 15.1676293 42 16.6339865 42 18.0459827 36.5957866 14.9678356 27.6 14.2426092zM22.5 18L25.5 18C26.0522847 18 26.5 18.4477153 26.5 19L26.5 39.5C26.5 40.8807119 25.3807119 42 24 42L24 42C22.6192881 42 21.5 40.8807119 21.5 39.5L21.5 19C21.5 18.4477153 21.9477153 18 22.5 18z" fill="#d4ff00" fill-rule="evenodd" transform="translate(-6 -8)"></path>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        ماین کردن داناکوین
    </p>
</div>

<div class="dashboard-mine-btn" onclick="showSection('report')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <g id="Page-1" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                <g id="support" fill="#eeff00" transform="translate(42.666667, 42.666667)">
                    <path d="M379.734355,174.506667 C373.121022,106.666667 333.014355,-2.13162821e-14 209.067688,-2.13162821e-14 C85.1210217,-2.13162821e-14 45.014355,106.666667 38.4010217,174.506667 C15.2012632,183.311569 -0.101643453,205.585799 0.000508304259,230.4 L0.000508304259,260.266667 C0.000508304259,293.256475 26.7445463,320 59.734355,320 C92.7241638,320 119.467688,293.256475 119.467688,260.266667 L119.467688,230.4 C119.360431,206.121456 104.619564,184.304973 82.134355,175.146667 C86.4010217,135.893333 107.307688,42.6666667 209.067688,42.6666667 C310.827688,42.6666667 331.521022,135.893333 335.787688,175.146667 C313.347976,184.324806 298.68156,206.155851 298.667688,230.4 L298.667688,260.266667 C298.760356,283.199651 311.928618,304.070103 332.587688,314.026667 C323.627688,330.88 300.801022,353.706667 244.694355,360.533333 C233.478863,343.50282 211.780225,336.789048 192.906491,344.509658 C174.032757,352.230268 163.260418,372.226826 167.196286,392.235189 C171.132153,412.243552 188.675885,426.666667 209.067688,426.666667 C225.181549,426.577424 239.870491,417.417465 247.041022,402.986667 C338.561022,392.533333 367.787688,345.386667 376.961022,317.653333 C401.778455,309.61433 418.468885,286.351502 418.134355,260.266667 L418.134355,230.4 C418.23702,205.585799 402.934114,183.311569 379.734355,174.506667 Z M76.8010217,260.266667 C76.8010217,269.692326 69.1600148,277.333333 59.734355,277.333333 C50.3086953,277.333333 42.6676884,269.692326 42.6676884,260.266667 L42.6676884,230.4 C42.6676884,224.302667 45.9205765,218.668499 51.2010216,215.619833 C56.4814667,212.571166 62.9872434,212.571166 68.2676885,215.619833 C73.5481336,218.668499 76.8010217,224.302667 76.8010217,230.4 L76.8010217,260.266667 Z M341.334355,230.4 C341.334355,220.97434 348.975362,213.333333 358.401022,213.333333 C367.826681,213.333333 375.467688,220.97434 375.467688,230.4 L375.467688,260.266667 C375.467688,269.692326 367.826681,277.333333 358.401022,277.333333 C348.975362,277.333333 341.334355,269.692326 341.334355,260.266667 L341.334355,230.4 Z"></path>
                </g>
            </g>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        گزارش به پشتیبانی
    </p>
</div>

<div class="dashboard-mine-btn" onclick="showSection('exchange')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <style type="text/css">
                .st0{fill:none;stroke:#e1ff00;stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:10;}
                .st1{fill:none;stroke:#e1ff00;stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:10;}
            </style>
            <g id="Bitcoin_x2C__BTC">
                <g id="XMLID_91_">
                    <path class="st1" d="M15.67,25.13l0.58-2.36c2.81,0.53,4.92,0.32,5.81-2.22 c0.72-2.04-0.03-3.22-1.51-3.99c1.08-0.25,1.89-0.96,2.1-2.42h0c0.3-2-1.22-3.07-3.3-3.79l0.56-2.25 M12.71,24.39l0.57-2.34 c0.45,0.12,0.89,0.23,1.31,0.34 M14.73,9.2c-0.36-0.08-3.32-0.82-3.32-0.82l-0.44,1.76c0,0,1.22,0.28,1.2,0.3 c0.67,0.17,0.79,0.61,0.77,0.96l-1.85,7.41c-0.08,0.2-0.29,0.51-0.75,0.39c0.02,0.02-1.2-0.3-1.2-0.3l-0.82,1.89 c0,0,2.93,0.74,3.32,0.84 M17.71,9.87c-0.43-0.11-0.88-0.21-1.32-0.31l0.54-2.2 M14.71,16.45c1.12,0.28,4.69,0.83,4.16,2.96l0,0 c-0.51,2.04-3.95,0.94-5.07,0.66l0.5-2.01 M15.95,11.51c0.93,0.23,3.92,0.66,3.44,2.6c-0.46,1.86-3.33,0.91-4.26,0.68l0.42-1.68" id="Bitcoin_x2C__BTC_x2C__cryptocurrency_2_"></path>
                    <circle class="st1" cx="16" cy="16" id="XMLID_173_" r="14.5"></circle>
                </g>
            </g>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        بازار کریپتو
    </p>
</div>
<div class="dashboard-mine-btn" onclick="showSection('barracks')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <path fill="#eeff00" d="M247 28v80h18V28zm35 0v64l80-32zm-26 96c-48 48-144 112-192 128 0 64-16 208-32 240h160c16-16 64-144 64-192 0 48 48 176 64 192h160c-16-32-32-176-32-240-48-16-144-80-192-128zM112 300h80v80h-80z"></path>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        پادگان
    </p>
</div>
<div class="dashboard-mine-btn" onclick="showSection('attack')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <g>
                <g>
                    <path fill="#ffea00" d="M509.293,422.101l-91.253-91.247l39.905-39.903c3.61-3.61,3.61-9.46,0-13.07c-3.61-3.61-9.458-3.61-13.067,0 l-30.392,30.392l-84.025-87.192l90.403-87.107c1.299-1.254,2.211-2.87,2.608-4.65l18.482-83.167 c0.668-3.014-0.217-6.161-2.346-8.393c-2.139-2.236-5.243-3.237-8.275-2.716L352.83,48.867c-1.877,0.332-3.592,1.227-4.936,2.572 l-92.129,92.126l-84.169-87.343c-1.254-1.299-2.87-2.211-4.647-2.608L83.781,35.132c-3.014-0.641-6.154,0.214-8.393,2.344 c-2.229,2.132-3.249,5.245-2.716,8.277l13.816,78.5c0.334,1.877,1.227,3.594,2.572,4.936l90.743,90.743 c-0.618,0.377-1.211,0.808-1.746,1.34l-82.419,82.421l-35.05-35.053c-3.61-3.61-9.458-3.61-13.067,0c-3.61,3.61-3.61,9.46,0,13.07 l46.439,46.436L2.707,419.394C0.975,421.127,0,423.473,0,425.927s0.975,4.801,2.707,6.534l39.21,39.208 c1.805,1.807,4.169,2.71,6.534,2.71c2.364,0,4.729-0.902,6.534-2.71l91.249-91.246l39.901,39.901 c1.805,1.803,4.169,2.705,6.534,2.705c2.365,0,4.729-0.903,6.534-2.705c3.61-3.61,3.61-9.46,0-13.07l-30.391-30.391l85.514-82.41 l86.992,86.992l-35.05,35.05c-3.61,3.61-3.61,9.46,0,13.07c1.805,1.802,4.169,2.705,6.534,2.705c2.364,0,4.729-0.903,6.534-2.705 l46.434-46.434l91.249,91.249c1.805,1.805,4.169,2.707,6.533,2.707c2.365,0,4.729-0.903,6.534-2.707l39.21-39.21 c1.733-1.733,2.707-4.081,2.707-6.534C512,426.178,511.025,423.834,509.293,422.101z M358.904,66.573l62.006-10.919 l-14.881,66.951l-88.39,85.166l-49.047-50.895L358.904,66.573z M48.451,452.071l-26.143-26.143l84.72-84.713l26.139,26.139 L48.451,452.071z M155.738,363.793l-1.34-1.342c-0.39-0.659-0.835-1.293-1.401-1.859c-0.566-0.569-1.202-1.013-1.861-1.403 l-35.93-35.928c-0.39-0.668-0.837-1.309-1.41-1.881c-0.573-0.571-1.214-1.02-1.879-1.41l-3.21-3.21l82.419-82.416 c0.535-0.535,0.963-1.128,1.34-1.746l48.787,48.785L155.738,363.793z M360.864,361.896c-0.659,0.39-1.295,0.835-1.861,1.403 c-0.566,0.566-1.013,1.2-1.401,1.859l-3.22,3.222l-250.188-250.2L93.275,56.177l66.951,14.881l241.189,250.288l-1.331,1.331 c-0.666,0.39-1.309,0.839-1.879,1.41c-0.573,0.573-1.02,1.214-1.41,1.882L360.864,361.896z M463.549,454.776l-84.715-84.715 l26.139-26.139l84.72,84.713L463.549,454.776z"></path>
                </g>
            </g>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        حمله به کاربران
    </p>
</div>
            <div class="dashboard-mine-btn" onclick="showSection('transfer')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <path d="M19 20V14M19 14L21 16M19 14L17 16" stroke="#d4ff00" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"></path>
            <path d="M22 12C22 8.22876 22 6.34315 20.8284 5.17157C19.6569 4 17.7712 4 14 4M14 20H10C6.22876 20 4.34315 20 3.17157 18.8284C2 17.6569 2 15.7712 2 12C2 8.22876 2 6.34315 3.17157 5.17157C4.34315 4 6.22876 4 10 4" stroke="#d4ff00" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M10 16H6" stroke="#d4ff00" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M13 16H12.5" stroke="#d4ff00" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M2 10L7 10M22 10L11 10" stroke="#d4ff00" stroke-width="1.5" stroke-linecap="round"></path>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        انتقال داناکوین
    </p>
</div>
<div class="dashboard-mine-btn" onclick="showSection('leaderboard')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 502.664 502.664" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <g>
                <g>
                    <rect y="289.793" style="fill:#ffea00;" width="148.666" height="199.638"></rect>
                    <rect x="353.998" y="238.497" style="fill:#ffea00;" width="148.666" height="250.933"></rect>
                    <rect x="176.988" y="164.057" style="fill:#ffea00;" width="148.709" height="325.374"></rect>
                    <path style="fill:#ffea00;" d="M429.474,87.243l21.053,42.71l47.154,6.859l-34.082,33.241l8.024,46.96l-42.149-22.175 l-42.149,22.175l8.024-46.96l-34.082-33.241l47.111-6.86L429.474,87.243z"></path>
                    <path style="fill:#ffea00;" d="M252.141,13.234l21.075,42.732l47.154,6.86l-34.082,33.262l8.046,46.916l-42.171-22.153 l-42.171,22.153l8.024-46.916l-34.082-33.262l47.132-6.86L252.141,13.234z"></path>
                    <path style="fill:#ffea00;" d="M71.744,137.05l21.053,42.732l47.154,6.881l-34.06,33.219l8.024,46.938l-42.171-22.153 l-42.171,22.175l8.046-46.938L3.538,186.684l47.132-6.881L71.744,137.05z"></path>
                </g>
            </g>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        جدول برترین‌ها
    </p>
</div>
<div class="dashboard-mine-btn" onclick="showSection('news')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 192 192" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <path d="M29.977 29.889h132.021v131.89H29.977zm33.749 34.092v0m30.34 0h36.211M63.726 96.06v0m30.34 0h36.211m-67.05 31.936v0m30.34 0h36.211" style="fill:none;stroke:#ffdd00;stroke-width:12;stroke-linecap:round;stroke-linejoin:round;stroke-miterlimit:57.5;paint-order:stroke markers fill"></path>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        اخبار و رویدادها
    </p>
</div>
<div class="dashboard-mine-btn" onclick="showSection('portfolio')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <path d="M19 12C19 12.5523 18.5523 13 18 13C17.4477 13 17 12.5523 17 12C17 11.4477 17.4477 11 18 11C18.5523 11 19 11.4477 19 12Z" fill="#fff700"></path>
            <path fill-rule="evenodd" clip-rule="evenodd" d="M9.94358 3.25H13.0564C14.8942 3.24998 16.3498 3.24997 17.489 3.40314C18.6614 3.56076 19.6104 3.89288 20.3588 4.64124C21.2831 5.56563 21.5777 6.80363 21.6847 8.41008C22.2619 8.6641 22.6978 9.2013 22.7458 9.88179C22.7501 9.94199 22.75 10.0069 22.75 10.067C22.75 10.0725 22.75 10.0779 22.75 10.0833V13.9167C22.75 13.9221 22.75 13.9275 22.75 13.933C22.75 13.9931 22.7501 14.058 22.7458 14.1182C22.6978 14.7987 22.2619 15.3359 21.6847 15.5899C21.5777 17.1964 21.2831 18.4344 20.3588 19.3588C19.6104 20.1071 18.6614 20.4392 17.489 20.5969C16.3498 20.75 14.8942 20.75 13.0564 20.75H9.94359C8.10583 20.75 6.65019 20.75 5.51098 20.5969C4.33856 20.4392 3.38961 20.1071 2.64124 19.3588C1.89288 18.6104 1.56076 17.6614 1.40314 16.489C1.24997 15.3498 1.24998 13.8942 1.25 12.0564V11.9436C1.24998 10.1058 1.24997 8.65019 1.40314 7.51098C1.56076 6.33856 1.89288 5.38961 2.64124 4.64124C3.38961 3.89288 4.33856 3.56076 5.51098 3.40314C6.65019 3.24997 8.10582 3.24998 9.94358 3.25ZM20.1679 15.75H18.2308C16.0856 15.75 14.25 14.1224 14.25 12C14.25 9.87756 16.0856 8.25 18.2308 8.25H20.1679C20.0541 6.90855 19.7966 6.20043 19.2981 5.7019C18.8749 5.27869 18.2952 5.02502 17.2892 4.88976C16.2615 4.75159 14.9068 4.75 13 4.75H10C8.09318 4.75 6.73851 4.75159 5.71085 4.88976C4.70476 5.02502 4.12511 5.27869 3.7019 5.7019C3.27869 6.12511 3.02502 6.70476 2.88976 7.71085C2.75159 8.73851 2.75 10.0932 2.75 12C2.75 13.9068 2.75159 15.2615 2.88976 16.2892C3.02502 17.2952 3.27869 17.8749 3.7019 18.2981C4.12511 18.7213 4.70476 18.975 5.71085 19.1102C6.73851 19.2484 8.09318 19.25 10 19.25H13C14.9068 19.25 16.2615 19.2484 17.2892 19.1102C18.2952 18.975 18.8749 18.7213 19.2981 18.2981C19.7966 17.7996 20.0541 17.0915 20.1679 15.75ZM5.25 8C5.25 7.58579 5.58579 7.25 6 7.25H10C10.4142 7.25 10.75 7.58579 10.75 8C10.75 8.41421 10.4142 8.75 10 8.75H6C5.58579 8.75 5.25 8.41421 5.25 8ZM20.9235 9.75023C20.9032 9.75001 20.8766 9.75 20.8333 9.75H18.2308C16.8074 9.75 15.75 10.8087 15.75 12C15.75 13.1913 16.8074 14.25 18.2308 14.25H20.8333C20.8766 14.25 20.9032 14.25 20.9235 14.2498C20.936 14.2496 20.9426 14.2495 20.9457 14.2493L20.9479 14.2492C21.1541 14.2367 21.2427 14.0976 21.2495 14.0139C21.2495 14.0139 21.2497 14.0076 21.2498 13.9986C21.25 13.9808 21.25 13.9572 21.25 13.9167V10.0833C21.25 10.0428 21.25 10.0192 21.2498 10.0014C21.2497 9.99238 21.2495 9.98609 21.2495 9.98609C21.2427 9.90242 21.1541 9.7633 20.9479 9.75076C20.9479 9.75076 20.943 9.75043 20.9235 9.75023Z" fill="#fff700"></path>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        مشاهده کل دارایی
    </p>
</div>
<div class="dashboard-mine-btn" onclick="openChat()" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <path d="M17 3.33782C15.5291 2.48697 13.8214 2 12 2C6.47715 2 2 6.47715 2 12C2 13.5997 2.37562 15.1116 3.04346 16.4525C3.22094 16.8088 3.28001 17.2161 3.17712 17.6006L2.58151 19.8267C2.32295 20.793 3.20701 21.677 4.17335 21.4185L6.39939 20.8229C6.78393 20.72 7.19121 20.7791 7.54753 20.9565C8.88837 21.6244 10.4003 22 12 22C17.5228 22 22 17.5228 22 12C22 10.1786 21.513 8.47087 20.6622 7" stroke="#fff700" stroke-width="1.5" stroke-linecap="round"></path>
            <path d="M8 12H8.009M11.991 12H12M15.991 12H16" stroke="#fff700" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></path>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        صحبت با اعضای سایت
    </p>
</div>


<div class="dashboard-mine-btn" onclick="showSection('shop')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 64 64" xmlns="http://www.w3.org/2000/svg" stroke-width="3" stroke="#fff700" fill="none" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <path d="M52,27.18V52.76a2.92,2.92,0,0,1-3,2.84H15a2.92,2.92,0,0,1-3-2.84V27.17"></path>
            <polyline points="26.26 55.52 26.26 38.45 37.84 38.45 37.84 55.52"></polyline>
            <path d="M8.44,19.18s-1.1,7.76,6.45,8.94a7.17,7.17,0,0,0,6.1-2A7.43,7.43,0,0,0,32,26a7.4,7.4,0,0,0,5,2.49,11.82,11.82,0,0,0,5.9-2.15,6.66,6.66,0,0,0,4.67,2.15,8,8,0,0,0,7.93-9.3L50.78,9.05a1,1,0,0,0-.94-.65H14a1,1,0,0,0-.94.66Z"></path>
            <line x1="8.44" y1="19.18" x2="55.54" y2="19.18"></line>
            <line x1="21.04" y1="19.18" x2="21.04" y2="8.4"></line>
            <line x1="32.05" y1="19.18" x2="32.05" y2="8.4"></line>
            <line x1="43.01" y1="19.18" x2="43.01" y2="8.4"></line>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        فروشگاه
    </p>
</div>
<div class="dashboard-mine-btn" onclick="showSection('myproducts')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_iconCarrier">
            <defs>
                <clipPath id="clip-box">
                    <rect width="32" height="32"></rect>
                </clipPath>
            </defs>
            <g id="box" clip-path="url(#clip-box)">
                <g id="Group_3126" data-name="Group 3126" transform="translate(-260 -104)">
                    <g id="Group_3116" data-name="Group 3116">
                        <g id="Group_3115" data-name="Group 3115">
                            <g id="Group_3114" data-name="Group 3114">
                                <path id="Path_3990" data-name="Path 3990" d="M291.858,111.843a.979.979,0,0,0-.059-.257.882.882,0,0,0-.055-.14.951.951,0,0,0-.184-.231.766.766,0,0,0-.061-.077c-.006,0-.014,0-.02-.01a.986.986,0,0,0-.374-.18l-.008,0h0l-14.875-3.377a1.008,1.008,0,0,0-.444,0L260.9,110.944a.984.984,0,0,0-.382.184c-.006.005-.014.005-.02.01-.026.021-.038.054-.062.077a.971.971,0,0,0-.183.231.882.882,0,0,0-.055.14.979.979,0,0,0-.059.257c0,.026-.017.049-.017.076v16.162a1,1,0,0,0,.778.975l14.875,3.377a1,1,0,0,0,.444,0l14.875-3.377a1,1,0,0,0,.778-.975V111.919C291.875,111.892,291.86,111.869,291.858,111.843ZM276,114.27l-3.861-.877L282.328,111l4.029.915Zm-9.2-.038,3.527.8v5.335l-.568-.247a.5.5,0,0,0-.351-.018l-1.483.472-1.125-.836Zm9.2-4.664,4.1.931-10.19,2.389-4.269-.969Zm-13.875,3.6L265.8,114v5.985a.5.5,0,0,0,.2.4l1.532,1.139a.5.5,0,0,0,.3.1.485.485,0,0,0,.151-.023l1.549-.493,1.1.475a.5.5,0,0,0,.7-.459V115.26l3.674.833v14.112l-12.875-2.922Zm27.75,14.112L277,130.205V116.093l12.875-2.922Z" fill="#d4ff00"></path>
                            </g>
                        </g>
                    </g>
                    <g id="Group_3119" data-name="Group 3119">
                        <g id="Group_3118" data-name="Group 3118">
                            <g id="Group_3117" data-name="Group 3117">
                                <path id="Path_3991" data-name="Path 3991" d="M278.841,127.452a.508.508,0,0,0,.11-.012l5.613-1.274a.5.5,0,0,0,.39-.488v-6.1a.5.5,0,0,0-.188-.39.5.5,0,0,0-.422-.1l-5.614,1.275a.5.5,0,0,0-.389.488v6.1a.5.5,0,0,0,.5.5Zm.5-6.2,4.613-1.047v5.074l-4.613,1.047Z" fill="#d4ff00"></path>
                            </g>
                        </g>
                    </g>
                    <g id="Group_3122" data-name="Group 3122">
                        <g id="Group_3121" data-name="Group 3121">
                            <g id="Group_3120" data-name="Group 3120">
                                <path id="Path_3992" data-name="Path 3992" d="M280.688,123.093a.524.524,0,0,0,.111-.012l1.918-.435a.5.5,0,0,0-.221-.976l-1.918.435a.5.5,0,0,0,.11.988Z" fill="#d4ff00"></path>
                            </g>
                        </g>
                    </g>
                    <g id="Group_3125" data-name="Group 3125">
                        <g id="Group_3124" data-name="Group 3124">
                            <g id="Group_3123" data-name="Group 3123">
                                <path id="Path_3993" data-name="Path 3993" d="M282.611,123.7l-2.029.44a.5.5,0,0,0,.106.989.492.492,0,0,0,.107-.011l2.029-.441a.5.5,0,0,0,.382-.594A.493.493,0,0,0,282.611,123.7Z" fill="#d4ff00"></path>
                            </g>
                        </g>
                    </g>
                </g>
            </g>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        محصولات من
    </p>
</div>

<div class="dashboard-mine-btn" onclick="showSection('sponsors')" style="cursor: pointer; text-align: center; margin: 15px 0; padding: 0; transition: all 0.3s ease; user-select: none;">
    <svg width="64px" height="64px" viewBox="0 0 24 24" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="filter: drop-shadow(0 0 10px #d4ff00);">
        <g id="SVGRepo_bgCarrier" stroke-width="0"></g>
        <g id="SVGRepo_tracerCarrier" stroke-linecap="round" stroke-linejoin="round"></g>
        <g id="SVGRepo_iconCarrier">
            <title>اسپانسرها</title>
            <g id="🔍-Product-Icons" stroke="none" stroke-width="1" fill="none" fill-rule="evenodd">
                <g id="ic_fluent_team_add_24_filled" fill="#d4ff00" fill-rule="nonzero">
                    <path d="M17.5,12 C20.5375661,12 23,14.4624339 23,17.5 C23,20.5375661 20.5375661,23 17.5,23 C14.4624339,23 12,20.5375661 12,17.5 C12,14.4624339 14.4624339,12 17.5,12 Z M17.5,13.9992349 L17.4101244,14.0072906 C17.2060313,14.0443345 17.0450996,14.2052662 17.0080557,14.4093593 L17,14.4992349 L16.9996498,16.9992349 L14.4976498,17 L14.4077742,17.0080557 C14.2036811,17.0450996 14.0427494,17.2060313 14.0057055,17.4101244 L13.9976498,17.5 L14.0057055,17.5898756 C14.0427494,17.7939687 14.2036811,17.9549004 14.4077742,17.9919443 L14.4976498,18 L17.0006498,17.9992349 L17.0011076,20.5034847 L17.0091633,20.5933603 C17.0462073,20.7974534 17.207139,20.9583851 17.411232,20.995429 L17.5011076,21.0034847 L17.5909833,20.995429 C17.7950763,20.9583851 17.956008,20.7974534 17.993052,20.5933603 L18.0011076,20.5034847 L18.0006498,17.9992349 L20.5045655,18 L20.5944411,17.9919443 C20.7985342,17.9549004 20.9594659,17.7939687 20.9965098,17.5898756 L21.0045655,17.5 L20.9965098,17.4101244 C20.9594659,17.2060313 20.7985342,17.0450996 20.5944411,17.0080557 L20.5045655,17 L17.9996498,16.9992349 L18,14.4992349 L17.9919443,14.4093593 C17.9549004,14.2052662 17.7939687,14.0443345 17.5898756,14.0072906 L17.5,13.9992349 Z M14.2540247,10 C15.0885672,10 15.8169906,10.4543496 16.2054276,11.1291814 C13.23532,11.7296535 11,14.3537833 11,17.5 C11,18.7891565 11.3752958,19.9906579 12.0225923,21.0012092 L12.002976,21 C9.51711551,21 7.50192738,18.9848119 7.50192738,16.4989513 L7.50192738,12.25 C7.50192738,11.0073593 8.5092867,10 9.75192738,10 L14.2540247,10 Z M7.40645343,10.000271 C6.89290875,10.5355324 6.56080951,11.2462228 6.50902592,12.0334718 L6.50192738,12.25 L6.50192738,16.4989513 C6.50192738,17.3455959 6.69319107,18.1475684 7.03486751,18.8640179 C6.70577369,18.9530495 6.35898976,19 6.00123996,19 C3.79141615,19 2,17.2085839 2,14.99876 L2,12.25 C2,11.059136 2.92516159,10.0843551 4.09595119,10.0051908 L4.25,10 L7.40645343,10.000271 Z M19.75,10 C20.9926407,10 22,11.0073593 22,12.25 L22.0008195,12.8103588 C20.8328473,11.6891263 19.2469007,11 17.5,11 L17.2568191,11.0044649 L17.2568191,11.0044649 C17.1013063,10.6296432 16.8768677,10.2893694 16.5994986,10.000271 L19.75,10 Z M18.5,4 C19.8807119,4 21,5.11928813 21,6.5 C21,7.88071187 19.8807119,9 18.5,9 C17.1192881,9 16,7.88071187 16,6.5 C16,5.11928813 17.1192881,4 18.5,4 Z M12,3 C13.6568542,3 15,4.34314575 15,6 C15,7.65685425 13.6568542,9 12,9 C10.3431458,9 9,7.65685425 9,6 C9,4.34314575 10.3431458,3 12,3 Z M5.5,4 C6.88071187,4 8,5.11928813 8,6.5 C8,7.88071187 6.88071187,9 5.5,9 C4.11928813,9 3,7.88071187 3,6.5 C3,5.11928813 4.11928813,4 5.5,4 Z" id="🎨-Color"></path>
                </g>
            </g>
        </g>
    </svg>
    <p style="margin-top: 10px; font-size: 16px; font-weight: bold; color: #d4ff00; text-shadow: 0 0 10px #d4ff00;">
        اسپانسر ها
    </p>
</div>

        </div>
    </div>


    
    <div id="portfolio" class="section">
        <h1>کل دارایی و آمار شما</h1>
        <div style="background:#ffffff11; padding:25px; border-radius:20px; margin:20px auto; max-width:700px; line-height:2.2;">
            <h2 style="color:#ff9800; margin-bottom:20px;">موجودی داناکوین</h2>
            <p style="font-size:24px; color:#4CAF50;">موجودی فعلی: <span id="portBalance">0</span> داناکوین</p>
            <p style="font-size:18px; color:#aaa;">گردش مالی : <span id="portTotalEarned">0</span> داناکوین</p>
            <hr style="border:1px solid #444; margin:30px 0;">
            <h2 style="color:#00bcd4; margin-bottom:20px;">دارایی رمز ارزها</h2>
            <div id="portCryptoList" style="font-size:18px; color:#fff;"></div>
            <p style="margin-top:15px; color:#aaa;">کل ارزش رمز ارزها: <span id="portCryptoValue">0</span> داناکوین</p>
            <hr style="border:1px solid #444; margin:30px 0;">
            <h2 style="color:#e91e63; margin-bottom:20px;">آمار خرید و فروش رمز ارز</h2>
            <p>تعداد کل خرید: <span id="portTotalBought">0</span> واحد</p>
            <p>تعداد کل فروش: <span id="portTotalSold">0</span> واحد</p>
            <hr style="border:1px solid #444; margin:30px 0;">
            <h2 style="color:#ff5722; margin-bottom:20px;">وضعیت نظامی</h2>
            <p>تعداد خانه سرباز : <span id="portBarrackSlots">0</span> خانه → حداکثر <span id="portMaxSoldiers">0</span> سرباز</p>
            <br>
            <br>
            <br>
            <br>
            <p>تعداد سرباز فعلی: <span id="portSoldiers">0</span></p>
            <br>
            <br>
            <br>
            <br>
            <p>تعداد خانه نگهبان: <span id="portGuardSlots">0</span> خانه → حداکثر <span id="portMaxGuards">0</span> نگهبان</p>
            <br>
            <br>
            <br>
            <br>
            <p>تعداد نگهبان فعلی: <span id="portGuards">0</span></p>
        </div>
    </div>

    <div id="guide" class="section">
        <h1 style="color:#ff9800; margin-bottom:30px;">راهنمای کامل داناکوین</h1>
        <p style="margin-bottom:40px; color:#ccc;">روی هر موضوع کلیک کنید تا توضیحات آن نمایش داده شود</p>

        <div class="guide-accordion">
        <button class="guide-btn">هدف بازی - استراتژی کلی برای موفقیت</button>
<div class="guide-panel">
    <p>
        بازی داناکوین یک شبیه‌ساز استراتژیک-اقتصادی هیجان‌انگیز است که هدف اصلی آن رسیدن به **بالاترین ثروت و قدرت** در میان همه بازیکنان و تسلط بر جدول رتبه‌بندی (لیدربورد) است. موفقیت در این بازی نیازمند ترکیبی هوشمندانه از فعالیت مداوم، تصمیم‌گیری‌های دقیق و استراتژی بلندمدت است.
        <br><br>
        مراحل اصلی پیشرفت و استراتژی پیشنهادی:
        <br>
        • <strong>فاز اول - شروع سریع:</strong> با ماین دستی (کلیک) داناکوین جمع کنید و در اسرع وقت قدرت کلیک خود را ارتقا دهید تا درآمدتان به صورت نمایی رشد کند.
        <br>
        • <strong>فاز دوم - درآمد خودکار:</strong> با خرید ماشین‌های استخراج (داناکوین، بیت‌کوین و لایت‌کوین) درآمد آفلاین بسازید و موجودی خود را به میلیون‌ها برسانید.
        <br>
        • <strong>فاز سوم - معامله و دفاع:</strong> در صرافی از نوسانات واقعی کریپتو سود کسب کنید و با خرید نگهبانان قوی، دارایی‌هایتان را در برابر حملات محافظت کنید.
        <br>
        • <strong>فاز نهایی - تسلط و غارت:</strong> ارتش سرباز بسازید، به بازیکنان دیگر حمله کنید و با غارت موفق ۵٪ از ثروت آن‌ها، خود را به صدر لیدربورد برسانید.
        <br><br>
        <strong>مثال کاربردی:</strong> بازیکنی که از روز اول روی ارتقای کلیک و خرید ماشین تمرکز می‌کند، بعد از چند هفته درآمد آفلاین میلیون‌ها داناکوین دارد. سپس نگهبانان قوی می‌خرد تا امن بماند، در صرافی سود می‌کند و با حملات هدفمند به رقبا، به سرعت به رتبه‌های برتر می‌رسد و ثروتش چند برابر می‌شود.
        <br><br>
        نکته مهم: این بازی پاداش فعالیت مداوم و استراتژی هوشمند را می‌دهد. هرچه زودتر شروع کنید و تعادل بین درآمد، دفاع و حمله را حفظ کنید، زودتر به جایگاه ثروتمندترین و قدرتمندترین بازیکن می‌رسید. رقابت شدید است — فقط قوی‌ترین‌ها در صدر می‌مانند!
    </p>
</div>

            <button class="guide-btn">ماین کردن داناکوین</button>
    <div class="guide-panel">
        <p>
            ماین با کلیک دستی ساده‌ترین و سریع‌ترین راه برای کسب داناکوین در ابتدای بازی است. با هر کلیک روی دکمه بزرگ ماین، مقدار مشخصی داناکوین (بر اساس قدرت کلیک فعلی‌تان) به موجودی شما اضافه می‌شود.
            <br><br>
            قابلیت‌های اصلی این بخش:
            <br>
            • هر کلیک مقدار داناکوین برابر با **قدرت کلیک** فعلی شما تولید می‌کند (شروع از ۱ و با ارتقا افزایش می‌یابد).
            <br>
            • سیستم ضد اتوکلیکر هوشمند: اگر خیلی سریع و غیرطبیعی کلیک کنید، ممکن است موقتاً بن شوید (بن تصاعدی از ۱ ساعت شروع می‌شود).
            <br>
            • کلیک دسته‌ای (batch): می‌توانید چندین کلیک را همزمان ارسال کنید تا سرعت بالاتر برود (اما همچنان تحت نظارت ضد تقلب است).
            <br><br>
            <strong>مثال کاربردی:</strong> وقتی تازه بازی را شروع کرده‌اید و هنوز ماشینی نخریده‌اید، هر چند دقیقه به بخش ماین سر بزنید و ۱۰۰-۲۰۰ کلیک بزنید. این کار به شما کمک می‌کند سریع به ۵,۰۰۰ داناکوین برای اولین ارتقا یا ۲۵۰,۰۰۰ برای خرید ماشین برسید.
            <br><br>
            نکته مهم: کلیک دستی برای شروع عالی است، اما با خرید ماشین‌های استخراج و ارتقاهای بعدی، به تدریج درآمد آفلاین شما آنقدر زیاد می‌شود که دیگر نیازی به کلیک مداوم نخواهید داشت. این بخش پایه‌ای برای رشد سریع حساب شماست!
        </p>
    </div>

            <button class="guide-btn">ارتقا قدرت کلیک</button>
    <div class="guide-panel">
        <p>
            ارتقا قدرت کلیک یکی از مهم‌ترین سرمایه‌گذاری‌های اولیه در بازی است. با پرداخت داناکوین، قدرت هر کلیک شما دو برابر می‌شود و هزینه ارتقای بعدی نیز افزایش می‌یابد (فرمول تصاعدی).
            <br><br>
            نحوه کار و مزایا:
            <br>
            • سطح ۰: قدرت ۱ داناکوین در هر کلیک → هزینه ارتقا به سطح ۱: ۵,۰۰۰ داناکوین
            <br>
            • سطح ۱: قدرت ۲ → هزینه بعدی ۱۰,۰۰۰
            <br>
            • سطح ۲: قدرت ۴ → هزینه بعدی ۲۰,۰۰۰ و به همین ترتیب (همیشه دو برابر)
            <br>
            • هر ارتقا بلافاصله اعمال می‌شود و قدرت کلیک شما برای همیشه افزایش می‌یابد.
            <br><br>
            <strong>مثال کاربردی:</strong> شما ۲۰,۰۰۰ داناکوین جمع کرده‌اید. ابتدا به سطح ۲ ارتقا می‌دهید (قدرت ۴ می‌شود). حالا با همان ۱۰۰ کلیک قبلی، به جای ۱۰۰ داناکوین، ۴۰۰ داناکوین به دست می‌آورید — یعنی ۴ برابر سریع‌تر پیشرفت می‌کنید.
            <br><br>
            نکته مهم: ارتقاهای اولیه (تا سطح ۵-۶) بازگشت سرمایه بسیار سریعی دارند. هرچه زودتر ارتقا دهید، زودتر به درآمدهای بالا و خرید ماشین‌های استخراج می‌رسید. این قابلیت برای بازیکنان فعال که دوست دارند سریع رشد کنند ایده‌آل است!
        </p>
    </div>

    <button class="guide-btn">بازار کریپتو </button>
<div class="guide-panel">
    <p>
        بخش صرافی یکی از قدرتمندترین ابزارها برای کسب سودهای کلان در بازی است. اینجا می‌توانید با استفاده از نوسانات واقعی بازار کریپتو، داناکوین خود را چند برابر کنید. قیمت‌ها مستقیماً از منابع معتبر جهانی (مانند CoinGecko) گرفته می‌شوند و هر ۶۰ ثانیه یک‌بار به‌روزرسانی می‌شوند.
        <br><br>
        قابلیت‌های اصلی صرافی:
        <br>
        • <strong>خرید (Buy):</strong> با پرداخت داناکوین، مقدار دلخواهی از یک کریپتو واقعی (مانند BTC، ETH، BNB، SOL و ...) را در قیمت لحظه‌ای خریداری کنید.
        <br>
        • <strong>فروش (Sell):</strong> کریپتوهای خریداری‌شده را در قیمت لحظه‌ای بفروشید و به جای آن داناکوین دریافت کنید.
        <br>
        • پشتیبانی از بیش از ۱۰ کریپتو محبوب با قیمت‌های واقعی و بدون کارمزد اضافی.
        <br><br>
        <strong>مثال کاربردی:</strong> شما ۰.۵ واحد اتریوم را وقتی قیمت هر واحد ۲۰۰,۰۰۰ داناکوین بود خریداری کرده‌اید (مجموع ۱۰۰,۰۰۰ داناکوین هزینه). چند ساعت بعد قیمت به ۲۲۰,۰۰۰ داناکوین می‌رسد. با زدن دکمه فروش، ۰.۵ واحد اتریوم خود را می‌فروشید و ۱۱۰,۰۰۰ داناکوین دریافت می‌کنید — یعنی ۱۰,۰۰۰ داناکوین سود خالص در یک تراکنش!
        <br><br>
        نکته مهم: این بخش برای بازیکنان با دانش نسبی از بازار کریپتو ایده‌آل است. استراتژی "خرید در کف و فروش در سقف" می‌تواند درآمد شما را بدون نیاز به کلیک مداوم یا ماینینگ چندین برابر کند. همیشه موجودی کریپتوهای خود را چک کنید و از نگهبانان قوی برای محافظت از داناکوین‌هایتان در برابر حملات استفاده کنید!
    </p>
</div>

            <button class="guide-btn">پادگان - خرید سرباز و نگهبان</button>
<div class="guide-panel">
    <p>
        بخش پادگان مرکز مدیریت نیروی نظامی شماست. اینجا می‌توانید سرباز برای حمله و نگهبان برای دفاع بخرید و ظرفیت نگهداری نیروی خود را افزایش دهید. این سیستم به شما اجازه می‌دهد در رقابت‌های استراتژیک بازی شرکت کنید و از دارایی‌هایتان محافظت کنید — حتی وقتی آفلاین هستید.
        <br><br>
        قابلیت‌های اصلی پادگان:
        <br>
        • <strong>خرید سرباز:</strong> هر سرباز فقط **۲۰ داناکوین** هزینه دارد و به قدرت تهاجمی شما اضافه می‌شود (برای غارت موفق در حمله به دیگران استفاده می‌شود).
        <br>
        • <strong>خرید نگهبان:</strong> هر نگهبان **۴۰ داناکوین** هزینه دارد و به قدرت دفاعی شما اضافه می‌شود (مهم‌ترین ابزار برای جلوگیری از غارت موجودی‌تان توسط مهاجمان).
        <br>
        • <strong>خرید ظرفیت پادگان:</strong> به ازای **۵,۰۰۰ داناکوین**، ظرفیت نگهداری **۱۰۰ واحد** (سرباز + نگهبان) افزایش می‌یابد — بدون ظرفیت کافی نمی‌توانید نیروی بیشتری بخرید.
        <br><br>
        <strong>مثال کاربردی:</strong> شما ۵۰۰,۰۰۰ داناکوین دارید و می‌خواهید از آن محافظت کنید. ابتدا ظرفیت پادگان را افزایش می‌دهید (مثلاً ۱۰ بار خرید ظرفیت = ۵۰,۰۰۰ داناکوین برای ۱,۰۰۰ واحد فضای نگهداری)، سپس ۵۰۰ نگهبان می‌خرید (۲۰,۰۰۰ داناکوین). حالا دفاع شما بسیار قوی است و مهاجمان شانس کمی برای غارت موفق دارند — حتی اگر آفلاین باشید.
        <br><br>
        نکته مهم: نگهبان‌ها اولویت بالاتری نسبت به سرباز دارند، چون دفاع از موجودی‌تان حیاتی است. همیشه تعادل بین حمله و دفاع را حفظ کنید و ظرفیت پادگان را قبل از خرید نیروی زیاد افزایش دهید. این بخش کلید موفقیت در رتبه‌های بالای لیدربورد و رقابت‌های استراتژیک بازی است!
    </p>
</div>

            <button class="guide-btn">حمله به کاربران</button>
<div class="guide-panel">
    <p>
        بخش حمله یکی از ویژگی‌های استراتژیک و هیجان‌انگیز بازی است که به شما اجازه می‌دهد با حمله به سایر بازیکنان، بخشی از داناکوین‌های آن‌ها را غارت کنید. دکمه حمله در کنار نام هر کاربر در بخش **لیدربورد (رتبه‌بندی)** نمایش داده می‌شود.
        <br><br>
        نحوه کار حمله:
        <br>
        • سرور قدرت تهاجمی شما (تعداد **سربازان**) را با قدرت دفاعی کاربر هدف (تعداد **نگهبانان**) مقایسه می‌کند.
        <br>
        • اگر سربازان شما بیشتر باشد → حمله موفقیت‌آمیز: شما **۵٪** از موجودی داناکوین هدف را غارت می‌کنید.
        <br>
        • هزینه حمله موفقیت‌آمیز: شما **۱۰٪** از سربازان خود و هدف **۵۰٪** از نگهبانان خود را از دست می‌دهد.
        <br>
        • اگر سربازان شما کمتر یا برابر باشد → حمله شکست‌خورده: هیچ غارتی انجام نمی‌شود و شما فقط **۵٪** از سربازان خود را از دست می‌دهید.
        <br>
        • محدودیت مهم: پس از هر حمله (موفق یا ناموفق) **۳۰ دقیقه** cooldown دارید و نمی‌توانید دوباره حمله کنید.
        <br><br>
        <strong>مثال کاربردی:</strong> شما ۱۲۰۰ سرباز دارید و به کاربری حمله می‌کنید که ۸۰۰ نگهبان دارد. حمله موفق است → شما ۵٪ از موجودی داناکوین او را دریافت می‌کنید، ۱۲۰ سرباز خود را از دست می‌دهید و او ۴۰۰ نگهبان خود را از دست می‌دهد. اگر بعد از این حمله سریع دوباره بخواهید حمله کنید، باید ۳۰ دقیقه صبر کنید.
        <br><br>
        نکته مهم: حمله یک ابزار استراتژیک قدرتمند است، اما ریسک هم دارد. همیشه قبل از حمله تعداد سربازان و نگهبانان را چک کنید و از نگهبانان قوی برای دفاع از موجودی خود استفاده کنید. این سیستم باعث می‌شود رقابت در رتبه‌های بالا بسیار هیجان‌انگیز و تاکتیکی شود!
    </p>
</div>

            <button class="guide-btn">انتقال داناکوین به کاربران</button>
    <div class="guide-panel">
        <p>
            انتقال داناکوین قابلیتی ساده اما بسیار کاربردی برای تعامل با دیگر بازیکنان است. با این دکمه می‌توانید مقدار دلخواهی از داناکوین خود را به حساب کاربری هر بازیکن دیگری بفرستید.
            <br><br>
            موارد استفاده رایج:
            <br>
            • اهدای هدیه به دوستان تازه‌وارد
            <br>
            • پرداخت بدهی یا جایزه در مسابقات خصوصی
            <br>
            • انجام معاملات مستقیم (مثل خرید/فروش آیتم یا کریپتو خارج از صرافی)
            <br>
            • کمک به اعضای تیم یا دوستان برای پیشرفت سریع‌تر
            <br><br>
            <strong>مثال کاربردی:</strong> دوست شما تازه بازی را شروع کرده و نیاز به ۱۰,۰۰۰ داناکوین برای اولین ارتقا دارد. نام کاربری او را (مثل ali123) وارد می‌کنید، مقدار ۱۰,۰۰۰ را می‌نویسید و دکمه انتقال را می‌زنید. داناکوین بلافاصله از حساب شما کسر و به حساب او واریز می‌شود — بدون کارمزد و به صورت آنی.
            <br><br>
            نکته مهم: انتقال کاملاً امن و غیرقابل بازگشت است، بنابراین نام کاربری و مقدار را دوبار چک کنید. این قابلیت باعث می‌شود جامعه بازی فعال‌تر و تعاملی‌تر شود و فرصت‌های همکاری و رقابت جالبی ایجاد کند!
        </p>
    </div>

            <button class="guide-btn">فروشگاه</button>
    <div class="guide-panel">
        <p>
            بخش فروشگاه قلب سیستم درآمدزایی خودکار بازی داناکوین است. در اینجا شما می‌توانید با استفاده از داناکوین‌های جمع‌آوری‌شده خود، ماشین‌های استخراج مختلف خریداری کنید که حتی وقتی آفلاین هستید یا بازی را نبسته‌اید، به صورت خودکار برایتان داناکوین، بیت‌کوین یا لایت‌کوین تولید کنند.
            <br><br>
            در حال حاضر سه نوع ماشین استخراج در فروشگاه موجود است:
            <br>
            • <strong>ماشین استخراج داناکوین</strong> (قیمت ۲۵۰,۰۰۰ داناکوین): تولید داناکوین با نرخ پایه ۱۰,۰۰۰ در دقیقه و ظرفیت اولیه ۵۰۰,۰۰۰.
            <br>
            • <strong>ماشین استخراج بیت‌کوین</strong> (قیمت ۵۰۰,۰۰۰ داناکوین): تولید بیت‌کوین واقعی (با نرخ پایه ۱ بیت‌کوین در دقیقه) که می‌توانید بعداً در صرافی به داناکوین تبدیل کنید.
            <br>
            • <strong>ماشین استخراج لایت‌کوین</strong> (قیمت ۲,۰۰۰ داناکوین): گزینه‌ای ارزان‌تر برای شروع استخراج لایت‌کوین با نرخ و ظرفیت مشابه بیت‌کوین اما هزینه بسیار کمتر.
            <br><br>
            <strong>مثال کاربردی:</strong> فرض کنید ۳۰۰,۰۰۰ داناکوین جمع کرده‌اید. می‌توانید ابتدا یک ماشین لایت‌کوین ارزان بخرید تا درآمد اضافی داشته باشید، سپس با درآمد آن به سمت خرید ماشین داناکوین یا بیت‌کوین بروید. هرچه تعداد ماشین‌هایتان بیشتر شود، درآمد آفلاین شما به شکل چشمگیری افزایش می‌یابد.
            <br><br>
            نکته مهم: تعداد کل ماشین‌های خریداری‌شده توسط همه کاربران در فروشگاه نمایش داده می‌شود — این نشان‌دهنده محبوبیت و رقابت در بازی است. خرید ماشین یک سرمایه‌گذاری بلندمدت است؛ هرچه زودتر شروع کنید، زودتر به درآمدهای بالا می‌رسید!
        </p>
    </div>

            <button class="guide-btn">محصولات من - مدیریت محصولات خریداری شده </button>
    <div class="guide-panel">
        <p>
            بخش «محصولات من» پنل شخصی شما برای مدیریت تمام ماشین‌های استخراجی است که خریداری کرده‌اید. در اینجا می‌توانید وضعیت هر ماشین را به صورت لحظه‌ای ببینید، درآمد انباشته‌شده را برداشت کنید، ماشین‌ها را ارتقا دهید، نام دلخواه بگذارید و حتی آن‌ها را به سایت بفروشید.
            <br><br>
            قابلیت‌های اصلی این بخش:
            <br>
            • <strong>برداشت درآمد:</strong> با زدن دکمه «برداشت» تمام داناکوین/بیت‌کوین/لایت‌کوین انباشته‌شده در مخزن ماشین به موجودی شما اضافه می‌شود.
            <br>
            • <strong>ارتقاء نرخ دریافت:</strong> افزایش مقدار تولید در هر دقیقه (مثلاً از ۱۰,۰۰۰ به مقادیر بالاتر).
            <br>
            • <strong>ارتقاء ظرفیت مخزن:</strong> افزایش سقف ذخیره‌سازی تا ماشین حتی برای مدت طولانی‌تر بدون برداشت کار کند.
            <br>
            • <strong>تغییر نام ماشین:</strong> برای شناسایی آسان‌تر، می‌توانید به هر ماشین نام دلخواه بدهید (مثل «ماشین طلایی» یا «قدرتمند۱»).
            <br>
            • <strong>جستجوی سریع:</strong> با کادر جستجو بالای لیست می‌توانید ماشین مورد نظرتان را با نام سریع پیدا کنید.
            <br>
            • <strong>فروش به سایت:</strong> اگر دیگر به ماشین نیاز ندارید، می‌توانید آن را با نصف قیمت خرید به سایت بفروشید و داناکوین بگیرید.
            <br><br>
            <strong>مثال کاربردی:</strong> شما ۵ ماشین داناکوین دارید و یکی را «سرعت۱» نام‌گذاری کرده‌اید. هر دقیقه به بخش محصولات من سر می‌زنید، درآمد انباشته‌شده را برداشت می‌کنید، سپس با بخشی از آن یکی از ماشین‌ها را ارتقا می‌دهید. بعد از چند روز، درآمد آفلاین شما آنقدر زیاد می‌شود که حتی نیازی به کلیک دستی ندارید.
            <br><br>
            نکته مهم: ماشین‌ها حتی وقتی از بازی خارج شده‌اید کار می‌کنند. هرچه ظرفیت و نرخ آن‌ها بالاتر باشد، درآمد آفلاین بیشتری خواهید داشت. مدیریت هوشمند این بخش کلید رسیدن به رتبه‌های بالای لیدربورد است!
        </p>
    </div>

            <button class="guide-btn">اسپانسرها</button>
    <div class="guide-panel">
        <p>
            بخش اسپانسرها فرصتی عالی برای کسب درآمد اضافی بدون نیاز به کلیک یا ماینینگ است. ادمین سایت با شرکت‌ها و پروژه‌های مختلف همکاری می‌کند و لینک‌های تبلیغاتی آن‌ها را به عنوان اسپانسر در بازی قرار می‌دهد. شما با بازدید از این اسپانسرها هم به رشد بازی کمک می‌کنید و هم سایت از درآمد تبلیغاتی سود می‌برد (که بخشی از آن به توسعه بازی اختصاص می‌یابد).
            <br><br>
            نحوه کار بسیار ساده است:
            <br>
            • لیست اسپانسرها به صورت کارت‌های زیبا نمایش داده می‌شود (جدیدترین در بالا).
            <br>
            • روی دکمه «بازدید از اسپانسر» کلیک می‌کنید → صفحه در تب جدید باز می‌شود.
            <br>
            • پس از بازدید کوتاه (معمولاً چند ثانیه تا یک دقیقه)، می‌توانید تب را ببندید و به بازی برگردید.
            <br>
            • هر بازدید شما به صورت خودکار شمارش می‌شود و به آمار کلی اسپانسر اضافه می‌گردد.
            <br><br>
            <strong>مثال کاربردی:</strong> هر روز چند دقیقه وقت می‌گذارید و تمام اسپانسرهای موجود را بازدید می‌کنید. این کار نه تنها به حمایت از بازی کمک می‌کند، بلکه باعث می‌شود ادمین بتواند ویژگی‌های جدید، رویدادها و جوایز بیشتری به بازی اضافه کند — که در نهایت به نفع همه کاربران است.
            <br><br>
            نکته مهم: بازدیدها کاملاً واقعی و بدون تقلب شمارش می‌شوند. اگر لینک باز نشود یا فیلترشکن لازم باشد، پیام خطا نمایش داده می‌شود. هرچه کاربران بیشتری اسپانسرها را بازدید کنند، اسپانسرهای بزرگ‌تر و جوایز بهتری به بازی اضافه خواهد شد. این یک همکاری برد-برد بین شما و توسعه‌دهندگان بازی است!
        </p>
    </div>

    <button class="guide-btn">قوانین و مقررات سایت</button>
<div class="guide-panel">
    <p>
        رعایت قوانین و مقررات سایت برای حفظ محیط سالم، عادلانه و لذت‌بخش برای همه کاربران الزامی است. داناکوین یک بازی اجتماعی-اقتصادی است و هرگونه رفتار نادرست می‌تواند به تجربه کلی جامعه آسیب بزند. ادمین و هلپرها حق کامل دارند که بر اساس شدت تخلف، اقدامات انضباطی اعمال کنند — این اقدامات برای حفاظت از حقوق همه بازیکنان و جلوگیری از سوءاستفاده انجام می‌شود.
        <br><br>
        رفتارهای ممنوعه و مجازات‌های مربوطه:
        <br>
        • <strong style="color:#f44336;">کلاهبرداری</strong> در هر شکل (مانند وعده‌های دروغین، فریب برای گرفتن داناکوین، یا معامله ناعادلانه): مجازات از <strong style="color:#f44336;">بن یک ماهه</strong> تا <strong style="color:#f44336;">بن ابدی</strong> یا <strong style="color:#f44336;">حذف کامل حساب کاربری</strong>.
        <br>
        • <strong style="color:#f44336;">فحاشی، توهین یا استفاده از کلمات رکیک</strong> در چت عمومی، خصوصی یا هر بخش دیگری از سایت: ابتدا اخطار، سپس <strong style="color:#f44336;">بن موقت (یک ماه)</strong> و در صورت تکرار <strong style="color:#f44336;">بن ابدی</strong>.
        <br>
        • <strong style="color:#f44336;">بی‌احترامی به ادمین یا هلپرها</strong> (مانند تهدید، تمسخر یا نافرمانی عمدی): مجازات فوری از <strong style="color:#f44336;">بن یک ماهه</strong> تا <strong style="color:#f44336;">حذف حساب</strong> بسته به شدت رفتار.
        <br>
        • هرگونه تلاش برای <strong style="color:#f44336;">تقلب، استفاده از اتوکلیکر، بات یا ابزارهای غیرمجاز</strong>: تشخیص خودکار یا گزارش → <strong style="color:#f44336;">بن تصاعدی</strong> و در موارد شدید <strong style="color:#f44336;">حذف دائمی حساب</strong>.
        <br><br>
        نکته ویژه در مورد <strong style="color:#4CAF50;">فروش داناکوین با پول واقعی</strong>:
        <br>
        کاربران کاملاً <strong style="color:#4CAF50;">مجاز هستند</strong> که خارج از سایت و به صورت خصوصی، داناکوین خود را با پول واقعی به یکدیگر بفروشند یا بخرند. سایت هیچ دخالتی در این معاملات شخصی ندارد و هیچ کارمزدی دریافت نمی‌کند. اما اگر گزارش معتبر و مستندی از <strong style="color:#f44336;">کلاهبرداری</strong> در این معاملات (مثل دریافت پول و عدم تحویل داناکوین یا بالعکس) دریافت شود، ادمین حق دارد با فرد متخلف برخورد کند — از <strong style="color:#f44336;">بن موقت</strong> تا <strong style="color:#f44336;">حذف کامل حساب</strong>. هدف این قانون جلوگیری از سوءاستفاده و حفظ اعتماد جامعه است.
        <br><br>
        <strong>مثال کاربردی:</strong> کاربری در چت عمومی شروع به فحاشی و توهین به دیگران می‌کند → ابتدا اخطار می‌گیرد. اگر ادامه دهد، حسابش برای یک ماه بن می‌شود و تمام پیشرفتش متوقف می‌ماند. یا کاربری قول می‌دهد ۱ میلیون داناکوین را در ازای پول واقعی بفروشد، پول را می‌گیرد اما داناکوین را تحویل نمی‌دهد → پس از گزارش و بررسی، حساب او برای همیشه حذف می‌شود تا دیگران آسیب نبینند.
        <br><br>
        <strong style="color:#f44336; font-size:18px;">نکته مهم:</strong> این قوانین برای حفاظت از همه شماست. همیشه با احترام رفتار کنید، معاملات خصوصی خود را با احتیاط و با افراد معتبر انجام دهید و هرگونه تخلف را از طریق بخش گزارش‌دهی اطلاع دهید. رعایت قوانین = تجربه‌ای لذت‌بخش و طولانی برای همه. تخلف = از دست دادن تمام پیشرفت و دسترسی به بازی. انتخاب با شماست — بازی پاک و عادلانه لذت بیشتری دارد!
    </p>
</div>


        </div>
   
   


        </section>
    
</div>

<div id="report" class="section">
        <h1>گزارش به پشتیبانی</h1>
        <div style="background:#ffffff11; padding:30px; border-radius:20px; max-width:600px; margin:30px auto;">
            <p style="margin-bottom:20px; color:#ff9800;">لطفاً مشکل خود را با دقت گزارش دهید:</p>
            
            <select id="reportSubject" style="width:100%; padding:15px; margin:10px 0; border-radius:10px; font-size:16px; background:#333; color:white; border:2px solid #ff9800;">
                <option value="">-- انتخاب موضوع گزارش --</option>
                <option value="کلاهبرداری">کلاهبرداری</option>
                <option value="پیدا شدن باگ">پیدا شدن باگ</option>
                <option value="مشکل در سایت">مشکل در سایت</option>
                <option value="درخواست برداشتن محدودیت در بخش ماینینگ">درخواست برداشتن محدودیت ماین</option>
                <option value="مزاحمت کاربر">مزاحمت کاربر</option>
            </select>

            <textarea id="reportMessage" placeholder="توضیحات کامل مشکل خود را اینجا بنویسید..." style="width:100%; height:150px; padding:15px; margin:10px 0; border-radius:10px; background:#333; color:white; border:2px solid #ff9800; font-size:16px; resize:vertical;"></textarea>

            <button class="btn" style="width:100%; padding:15px; font-size:18px;" onclick="submitReport()">ثبت گزارش</button>
        </div>
    </div>
    
    <div id="adminDashboard" class="section">
        <h1>داشبورد ادمین</h1>
        
        <p style="margin-bottom:30px; color:#4CAF50;">خوش آمدید، ادمین! شما مجاز به انجام تمامی عملیات امنیتی هستید.</p>
        
        <div class="dashboard-grid">
            <button class="btn" onclick="showSection('adminUsers')">مدیریت کاربران</button>
            <button class="btn" onclick="showSection('adminToggleBan')">مسدود/باز کردن حساب</button>
            <button class="btn" onclick="showSection('adminGiveCoin')">دادن داناکوین</button>
            <button class="btn" onclick="showSection('adminUserCount')">تعداد کاربران سایت</button>
            <button class="btn" onclick="showSection('adminDeleteUser')" style="background:#e91e63;">حذف کامل حساب کاربری</button>
            <button class="btn" onclick="openChat()">صحبت با اعضای سایت</button>
            <button class="btn" onclick="showSection('adminBannedUsers')">مشاهده حساب‌های بن شده</button>
            <button class="btn" onclick="showSection('addSponsor')">ثبت اسپانسر</button>
            <button class="btn" onclick="showSection('adminReports')">مشاهده گزارشات</button>
            <button class="btn" onclick="showSection('adminSponsors')">وضعیت اسپانسر ها</button>
            <button class="btn" onclick="showSection('sendMessage')">ارسال پیام</button>
            <div id="helperCreateButton" style="display:none;">
    <button class="btn" style="background:#9c27b0; color:white;" onclick="showSection('createHelper')">ایجاد حساب هلپری</button>
</div>
                
            
        
    

    

        </div>
        <br>
        <br>
        <br>
        <br>
        <br>
        <br>
        <br>
        <br>
        <br>
        <br>
        <br>
        <br>
        <section id="about-us-detailed-guide" style="padding: 15px; direction: rtl; text-align: justify; line-height: 1.8;">
    <h2 style="text-align: center; border-bottom: 2px solid #eee; padding-bottom: 10px;">معرفی جامع سایت داناکوین (DanaCoin)</h2>

    

    <h3 style="color: #28a745;">۲. هدف نهایی کاربر از بازی کردن چیست؟ 🎯</h3>
    <p>
        هدف اصلی شما در بازی داناکوین، دستیابی به **بالاترین ثروت و نفوذ** در میان تمام بازیکنان و تسلط بر جدول رتبه‌بندی است. این بازی یک شبیه‌ساز بقای استراتژیک-اقتصادی است که موفقیت در آن نیازمند ترکیبی از فعالیت مداوم و تصمیمات هوشمندانه است.
        <br>
        شما باید دارایی اولیه خود را با استفاده از **ماینینگ (کلیک کردن)** به سرعت افزایش دهید و در اسرع وقت اقدام به **ارتقاء ضریب کلیک** خود نمایید تا جریان درآمد شما به صورت نمایی رشد کند. این فرآیند، فاز اول رشد شماست.
        <br>
        در فاز بعدی، هدف شما تبدیل شدن به یک معامله‌گر و استراتژیست نظامی موفق است. شما باید با **معامله ارزهای دیجیتال** (بر اساس نوسانات لحظه‌ای واقعی)، سودهای بزرگ کسب کنید و در عین حال، دارایی‌های خود را با خرید **نگهبانان** به طور کامل بیمه نمایید.
        <br>
        هدف نهایی این است که با تشکیل ارتشی از **سربازان** و انجام حملات موفق (Raid) به بازیکنان ضعیف‌تر و حتی قوی‌تر، درصد مشخصی از ثروت آن‌ها را غارت کنید و نام خود را به عنوان ثروتمندترین و قدرتمندترین بازیکن در صدر جدول رتبه‌بندی تثبیت کنید.
    </p>

    <h2 style="text-align: center; border-bottom: 2px solid #eee; padding-bottom: 10px; margin-top: 30px;">راهنمای گام به گام دکمه‌ها و عملکردها</h2>

    <h3 style="color: #ffc107;">۳-۱. دکمه بزرگ ماینینگ (کلیک) ⛏️</h3>
    <p>
        این دکمه، موتور محرک کسب درآمد شما در بازی است و یک عملکرد حیاتی دارد. وظیفه آن، افزودن مستقیم داناکوین به موجودی شما بر اساس **قدرت کلیک** فعلی حساب کاربری شماست.
        <br>
        وقتی روی این دکمه کلیک می‌کنید، یک درخواست به سرور ارسال می‌شود و سرور مقدار داناکوین متناسب را به موجودی شما اضافه کرده و بلافاصله آن را روی صفحه نمایش به‌روز می‌کند.
        <br>
        **مثال کاربردی:** فرض کنید شما با ارتقاءهای متوالی، قدرت کلیک خود را به **۷۵ داناکوین** رسانده‌اید. با هر بار فشار دادن دکمه بزرگ ماینینگ، **۷۵ داناکوین** به موجودی کل شما اضافه می‌شود. اگر در یک دقیقه بتوانید ۶۰ بار کلیک کنید، درآمد شما در آن دقیقه ۴۵۰۰ داناکوین خواهد بود.
        <br>
        توجه داشته باشید که سیستم دارای قابلیت **ضد تقلب** است و اگر با استفاده از نرم‌افزارهای خودکار کلیک کنید، ممکن است برای مدت زمان مشخصی مسدود (Ban) شوید؛ بنابراین باید به صورت دستی اقدام به کلیک نمایید.
    </p>

    <h3 style="color: #17a2b8;">۳-۲. دکمه ارتقاء ضریب کلیک (Upgrade) ⬆️</h3>
    <p>
        دکمه ارتقاء، مهم‌ترین اهرم رشد مالی شماست و با استفاده از آن، پتانسیل درآمدزایی خود را چند برابر می‌کنید. با هر بار کلیک بر روی این دکمه، **ضریب یا قدرت کلیک** شما به صورت دقیق **دو برابر** می‌شود.
        <br>
        این ارتقاء، تأثیری مستقیم بر درآمد لحظه‌ای شما از دکمه ماینینگ دارد و قیمت آن بر اساس قدرت کلیک فعلی شما تعیین می‌شود و به صورت تصاعدی افزایش می‌یابد؛ یعنی هر چه قدرت شما بیشتر باشد، ارتقاء بعدی گران‌تر خواهد بود.
        <br>
        **مثال کاربردی:** اگر شما در حال حاضر قدرت کلیک **۴۰ داناکوین** دارید و هزینه ارتقاء مثلاً ۵۰۰۰ داناکوین است. با زدن دکمه ارتقاء، ۵۰۰۰ داناکوین از شما کسر می‌شود و قدرت کلیک شما فوراً به **۸۰ داناکوین** می‌رسد.
        <br>
        این عمل باید اولین اولویت شما برای خرج کردن داناکوین‌های اولیه باشد، زیرا هر ارتقاء، سرعت شما را در رسیدن به ارتقاءهای بعدی و کسب ثروت بیشتر به طور چشمگیری افزایش می‌دهد.
    </p>

    <h3 style="color: #dc3545;">۳-۳. دکمه‌های خرید و فروش در صرافی 📈</h3>
    <p>
        این دکمه‌ها دروازه شما به سمت سودهای کلان از طریق نوسانات بازار هستند. دکمه **خرید (Buy)** به شما این امکان را می‌دهد که با پرداخت داناکوین، مقدار مشخصی از یک ارز دیجیتال واقعی (مانند اتریوم یا بیت‌کوین) را در قیمت لحظه‌ای خریداری کنید.
        <br>
        در مقابل، دکمه **فروش (Sell)** به شما اجازه می‌دهد تا ارز دیجیتال خریداری شده خود را در قیمت لحظه‌ای بفروشید و به جای آن، داناکوین دریافت کنید. قیمت‌ها هر ۶۰ ثانیه یک بار از منابع معتبر جهانی به‌روزرسانی می‌شوند.
        <br>
        **مثال کاربردی (فروش):** شما ۰.۵ واحد اتریوم در قیمت ۲۰۰,۰۰۰ داناکوین خریده‌اید. اگر قیمت اتریوم در بازار جهانی بالا برود و اکنون به ۲۲۰,۰۰۰ داناکوین رسیده باشد، شما با زدن دکمه فروش، ۰.۵ واحد اتریوم خود را با ۲۲۰,۰۰۰ داناکوین معاوضه می‌کنید و در این تراکنش ۲۰,۰۰۰ داناکوین سود خالص کسب خواهید کرد.
        <br>
        این بخش برای بازیکنانی مناسب است که دانش نسبی در مورد نوسانات بازار دارند و می‌توانند با خرید در قیمت پایین و فروش در قیمت بالا، سودهای بزرگی را بدون نیاز به کلیک‌های مداوم به دست آورند.
    </p>

    <h3 style="color: #fd7e14;">۳-۴. دکمه حمله (Raid) ⚔️</h3>
    <p>
        دکمه حمله، بخش استراتژیک و تهاجمی بازی است و برای غارت داناکوین از سایر بازیکنان مورد استفاده قرار می‌گیرد. این دکمه در کنار نام هر کاربر در بخش **رتبه‌بندی** نمایش داده می‌شود.
        <br>
        وقتی دکمه حمله را فشار می‌دهید، سرور قدرت تهاجمی شما (تعداد **سربازان** شما) را با قدرت دفاعی کاربر هدف (تعداد **نگهبانان** او) مقایسه می‌کند. نتیجه حمله کاملاً به این نسبت بستگی دارد.
        <br>
        **مثال کاربردی:** شما با ۱۲۰۰ سرباز به کاربری حمله می‌کنید که ۸۰۰ نگهبان دارد. حمله موفقیت‌آمیز است و شما **۵٪** از موجودی داناکوین او را غارت می‌کنید. در این فرآیند، شما ۱۰٪ از سربازان خود (۱۲۰ واحد) را از دست می‌دهید و هدف ۵۰٪ از نگهبانان خود (۴۰۰ واحد) را از دست می‌دهد.
        <br>
        اگر تعداد سربازان شما از نگهبانان هدف کمتر باشد، حمله شکست خورده و شما تنها ۵٪ از سربازان خود را از دست می‌دهید و غارتی صورت نمی‌گیرد. این دکمه همچنین دارای **محدودیت زمانی ۳۰ دقیقه‌ای** پس از هر حمله است.
    </p>

    <h3 style="color: #6f42c1;">۳-۵. دکمه خرید سرباز و خرید نگهبان (پادگان) 🛡️</h3>
    <p>
        این دو دکمه در بخش پادگان قرار دارند و امکان ایجاد نیروی نظامی برای دفاع و حمله را فراهم می‌آورند. دکمه **خرید سرباز** به ازای هر **۲۰ داناکوین** یک واحد سرباز به نیروی تهاجمی شما اضافه می‌کند.
        <br>
        دکمه **خرید نگهبان** به ازای هر **۴۰ داناکوین** یک واحد نگهبان به نیروی دفاعی شما اضافه می‌کند. نگهبانان مهم‌ترین دارایی شما برای جلوگیری از غارت ثروتتان توسط رقبا هستند، حتی زمانی که شما آفلاین هستید.
        <br>
        **مثال کاربردی:** اگر شما دارای ۵۰۰,۰۰۰ داناکوین هستید، بهتر است بخشی از آن را صرف خرید نگهبانان کنید. خرید ۵۰ نگهبان به قیمت ۲,۰۰۰ داناکوین، به طور قابل توجهی احتمال موفقیت مهاجمان را کاهش می‌دهد و ثروت شما را در برابر حملات ایمن می‌سازد.
        <br>
        توجه داشته باشید که برای نگهداری تعداد زیادی از سربازان یا نگهبانان، باید از دکمه **خرید ظرفیت پادگان** استفاده کنید که به ازای ۵۰۰۰ داناکوین، ۱۰۰ واحد به فضای نگهداری نیروی نظامی شما اضافه می‌کند.
    </p>

    <h3 style="color: #00bcd4;">۳-۶. دکمه انتقال (Transfer) 🎁</h3>
    <p>
        دکمه انتقال یک عملکرد ساده اما حیاتی برای تعاملات اجتماعی و اقتصادی درون بازی است. این دکمه به شما این امکان را می‌دهد که مقدار مشخصی از داناکوین‌های خود را به حساب کاربری بازیکنان دیگر منتقل کنید.
        <br>
        این قابلیت معمولاً برای **اهدای جایزه**، **پرداخت بدهی**، یا انجام **معاملات خارج از صرافی** با دیگر کاربران بازی مورد استفاده قرار می‌گیرد.
        <br>
        برای استفاده از آن، شما باید **نام کاربری دقیق** شخص دریافت‌کننده و **مقدار دقیق** داناکوین مورد نظر را در کادرهای مربوطه وارد کنید.
        <br>
        **مثال کاربردی:** شما می‌خواهید برای دوستی که تازه بازی را شروع کرده است، ۱۰,۰۰۰ داناکوین به عنوان هدیه بفرستید. نام کاربری او (مثلاً 'ali123') و مقدار (۱۰,۰۰۰) را وارد کرده و دکمه <code>انتقال</code> را می‌زنید. ۱۰,۰۰۰ داناکوین بلافاصله از حساب شما کسر و به حساب او واریز می‌شود.
        <br>
        این فرآیند به صورت آنی و بدون کارمزد (معمولاً) انجام می‌شود و یک راه سریع برای مدیریت دارایی‌ها بین کاربران است.
    </p>

</section>
    </div>

    <!-- ================== بخش جدید: ارسال پیام (ادمین) ================== -->
    <div id="sendMessage" class="section">
        <h1>ارسال پیام به کاربران</h1>
        <div style="max-width:600px; margin:0 auto;">

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin:40px 0;">
                <button class="btn" style="padding:20px; font-size:18px;" onclick="showSection('privateMessage')">
                    پیام به کاربران
                </button>
                <button class="btn" style="padding:20px; font-size:18px; background:#ff5722;" onclick="showSection('broadcastMessage')">
                    پیام به همه کاربران
                </button>
            </div>

        </div>
    </div>

    <!-- پیام خصوصی به یک کاربر -->
    <div id="privateMessage" class="section">
        <h1>ارسال پیام خصوصی</h1>
        <div style="background:#ffffff11; padding:30px; border-radius:20px; max-width:500px; margin:30px auto;">
            <input type="text" id="privateTarget" placeholder="نام کاربری گیرنده" style="width:100%; margin-bottom:15px;">
            <textarea id="privateText" placeholder="متن پیام شما..." style="width:100%; height:150px; padding:15px; border-radius:15px; background:#333; color:#fff; border:none; resize:vertical;"></textarea>
            <button class="btn" style="width:100%; margin-top:20px; padding:15px;" onclick="sendPrivateMessage()">ارسال پیام</button>
        </div>
    </div>

    <!-- پیام همگانی به همه -->
    <div id="broadcastMessage" class="section">
        <h1>ارسال پیام به همه کاربران</h1>
        <div style="background:#ffffff11; padding:30px; border-radius:20px; max-width:500px; margin:30px auto;">
            <textarea id="broadcastText" placeholder="این پیام برای همه کاربران ارسال میشود..." style="width:100%; height:150px; padding:15px; border-radius:15px; background:#333; color:#fff; border:none; resize:vertical;"></textarea>
            <button class="btn" style="width:100%; margin-top:20px; padding:15px; background:#e91e63;" onclick="sendBroadcastMessage()">ارسال به همه کاربران</button>
        </div>
    </div>

    <div id="adminReports" class="section">
        <h1>گزارشات کاربران</h1>
        <div style="background:#ffffff11; padding:20px; border-radius:15px; margin:20px auto; max-width:900px;">
            <p style="color:#ff9800; margin-bottom:20px;">لیست تمام گزارشات ارسال شده توسط کاربران:</p>
            <div id="reportsList">
                <p style="text-align:center; color:#aaa;">در حال بارگذاری گزارشات...</p>
            </div>
        </div>
    </div>

    <!-- ====================== بخش جدید: لیست کاربران بن شده ====================== -->
    <div id="adminBannedUsers" class="section">
        <h1>کاربران مسدود (بن) شده</h1>
        <p style="margin:20px 0;">تعداد کل بن‌شده: <b id="bannedCount">0</b> نفر</p>

        <!-- جستجوی سریع -->
        <div style="margin:20px auto; max-width:400px;">
    <input type="text" id="bannedSearch" placeholder="جستجو نام کاربری..." onkeyup="filterBannedUsers()" style="width:100%; padding:15px; font-size:18px; border-radius:15px; border:none;">
</div>

        <div style="overflow-x:auto;">
            <table class="userlist" style="width:100%; margin:20px auto; max-width:900px;">
                <thead>
                    <tr>
                        <th>نام کاربری</th>
                        <th>موجودی</th>
                        <th>تاریخ بن</th>
                        <th>عملیات</th>
                    </tr>
                </thead>
                <tbody id="bannedUsersTable">
                    <!-- اینجا با جاوااسکریپت پر میشه -->
                </tbody>
            </table>
        </div>
    </div>
    <!-- ====================== پایان بخش جدید ====================== -->

    <div id="adminDeleteUser" class="section">
        <h1>حذف کامل حساب کاربری</h1>
        <div class="admin-delete-action">
            <p>⚠️ هشدار: این عملیات **غیرقابل بازگشت** است و تمام داده‌های کاربر به صورت دائمی حذف خواهند شد.</p>
            <input type="text" id="deleteTargetUser" placeholder="نام کاربری برای حذف (دقیق وارد شود)" style="width:90%; color:#000;"><br>
            <button class="btn btn-delete-user" onclick="deleteUser()">حذف دائمی حساب</button>
        </div>
    </div>

    <div id="adminToggleBan" class="section">
        <h1>مسدود/باز کردن حساب</h1>
        <div class="admin-action">
            <input type="text" id="banTargetUser" placeholder="نام کاربری هدف" style="width:250px;"><br>
            <button class="btn" style="background:#f44336;" onclick="toggleBan(true)">مسدود کن</button>
            <button class="btn" style="background:#4CAF50;" onclick="toggleBan(false)">باز کن</button>
        </div>
    </div>

    <div id="adminGiveCoin" class="section">
        <h1>دادن داناکوین به کاربر</h1>
        <div class="admin-action">
            <input type="text" id="coinTargetUser" placeholder="نام کاربری هدف" style="width:250px;"><br>
            <input type="number" id="coinAmount" placeholder="مقدار داناکوین" min="1" style="width:250px;"><br>
            <button class="btn" style="background:#00bcd4;" onclick="giveCoin()">اهدای داناکوین</button>
        </div>
    </div>

    <div id="adminUserCount" class="section">
        <h1>تعداد کل کاربران سایت</h1>
        <p style="font-size:40px; margin-top:30px;">
            <span id="totalUserCount">0</span>
        </p>
    </div>

    <div id="adminUsers" class="section">
        <h1>لیست کاربران سایت</h1>
        <input type="text" id="userSearch" onkeyup="filterUsers()" placeholder="جستجوی کاربر..." style="width:300px; margin-bottom:20px;"><br>
        <div class="userlist">
            <table id="allUsersTable">
                <tr><th>نام کاربری</th><th>رمز</th><th>وضعیت</th><th>موجودی</th></tr>
            </table>
        </div>
    </div>

    <div id="mine" class="section">
        <h1>استخراج داناکوین</h1>
        <p style="font-size:30px;">موجودی: <span id="mineBalance">0</span> داناکوین</p>
  <p style="font-size:20px; color:#ccc;">سطح فعلی: <span id="mineMultiplier">1</span></p>
<p style="font-size:18px; color:#ff9800; margin-bottom:30px;">درآمد هر کلیک: <span id="mineClickValue">5</span></p>
        
        <button class="btn btn-big" onclick="mineClick(event)">کلیک کن!</button>
        
        <h2 style="margin-top:50px; color:#00bcd4;">ارتقاء ضریب</h2>
        <p style="font-size:18px;">هزینه ارتقاء بعدی: <span id="upgradeCost">10,000</span></p>
        <button class="btn buy-btn" onclick="upgradeMultiplier()">ارتقاء</button>
    </div>

    <div id="exchange" class="section">
        <h1>صرافی کریپتو</h1>


<div class="crypto-search-container">
    <input type="text" id="cryptoSearch" placeholder="مخفف رمز ارز را جستجو کنید" autocomplete="off">
    <svg class="search-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
    </svg>
</div>

        <div id="cryptoBalances" style="margin-top:20px; line-height:1.8;"></div>
        
        <table class="crypto-table">
            <thead>
                <tr>
                    <th>نام ارز</th>
                    <th>قیمت (DANA)</th>
                    <th>موجودی شما</th>
                    <th>مقدار معامله</th>
                    <th>عملیات</th>
                    <th>نمودار</th>
                </tr>
            </thead>
           <tbody id="exchangeTable">
    <tr id="BTC_row">
        <td data-label="نام ارز">بیت‌کوین (BTC)</td>
        <td data-label="قیمت (DANA)"><span id="priceBTC">0</span></td>
        <td data-label="موجودی شما"><span id="balBTC">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountBTC" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('BTC', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('BTC', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('BTC')">نمودار</button>
</td>
    </tr>
    <tr id="ETH_row">
        <td data-label="نام ارز">اتریوم (ETH)</td>
        <td data-label="قیمت (DANA)"><span id="priceETH">0</span></td>
        <td data-label="موجودی شما"><span id="balETH">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountETH" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('ETH', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('ETH', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('ETH')">نمودار</button>
</td>
    </tr>
    <tr id="BNB_row">
        <td data-label="نام ارز">بایننس‌کوین (BNB)</td>
        <td data-label="قیمت (DANA)"><span id="priceBNB">0</span></td>
        <td data-label="موجودی شما"><span id="balBNB">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountBNB" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('BNB', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('BNB', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('BNB')">نمودار</button>
</td>
    </tr>
    <tr id="SOL_row">
        <td data-label="نام ارز">سولانا (SOL)</td>
        <td data-label="قیمت (DANA)"><span id="priceSOL">0</span></td>
        <td data-label="موجودی شما"><span id="balSOL">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountSOL" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('SOL', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('SOL', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('SOL')">نمودار</button>
</td>
    </tr>

    <!-- ۶ ارز جدید -->
    <tr id="TAO_row">
        <td data-label="نام ارز">بیت‌تنسور (TAO)</td>
        <td data-label="قیمت (DANA)"><span id="priceTAO">0</span></td>
        <td data-label="موجودی شما"><span id="balTAO">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountTAO" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('TAO', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('TAO', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('TAO')">نمودار</button>
</td>
    </tr>
    <tr id="AAVE_row">
        <td data-label="نام ارز">آوه (AAVE)</td>
        <td data-label="قیمت (DANA)"><span id="priceAAVE">0</span></td>
        <td data-label="موجودی شما"><span id="balAAVE">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountAAVE" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('AAVE', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('AAVE', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('AAVE')">نمودار</button>
</td>
    </tr>
    <tr id="BCH_row">
        <td data-label="نام ارز">بیت کوین کش (BCH)</td>
        <td data-label="قیمت (DANA)"><span id="priceBCH">0</span></td>
        <td data-label="موجودی شما"><span id="balBCH">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountBCH" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('BCH', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('BCH', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('BCH')">نمودار</button>
</td>
    </tr>
    <tr id="ZEC_row">
        <td data-label="نام ارز">زی کش (ZEC)</td>
        <td data-label="قیمت (DANA)"><span id="priceZEC">0</span></td>
        <td data-label="موجودی شما"><span id="balZEC">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountZEC" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
       <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('ZEC', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('ZEC', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('ZEC')">نمودار</button>
</td>
    </tr>
    <tr id="XMR_row">
        <td data-label="نام ارز">مونرو (XMR)</td>
        <td data-label="قیمت (DANA)"><span id="priceXMR">0</span></td>
        <td data-label="موجودی شما"><span id="balXMR">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountXMR" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('XMR', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('XMR', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('XMR')">نمودار</button>
</td>
    </tr>
    <tr id="LTC_row">
        <td data-label="نام ارز">لایت کوین (LTC)</td>
        <td data-label="قیمت (DANA)"><span id="priceLTC">0</span></td>
        <td data-label="موجودی شما"><span id="balLTC">0</span></td>
        <td data-label="مقدار معامله"><input type="number" id="amountLTC" placeholder="تعداد" min="0.001" step="0.001" style="width:100px;"></td>
        <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('LTC', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('LTC', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('LTC')">نمودار</button>
</td>
    </tr>
    <tr id="YFI_row">
    <td data-label="نام ارز">یرن فایننس (YFI)</td>
    <td data-label="قیمت (DANA)"><span id="priceYFI">0</span></td>
    <td data-label="موجودی شما"><span id="balYFI">0</span></td>
    <td data-label="مقدار معامله"><input type="number" id="amountYFI" placeholder="تعداد" min="0.001" step="0.001"></td>
    <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('YFI', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('YFI', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('YFI')">نمودار</button>
</td>
</tr>
<tr id="PAXG_row">
    <td data-label="نام ارز">پکس گلد (PAXG)</td>
    <td data-label="قیمت (DANA)"><span id="pricePAXG">0</span></td>
    <td data-label="موجودی شما"><span id="balPAXG">0</span></td>
    <td data-label="مقدار معامله"><input type="number" id="amountPAXG" placeholder="تعداد" min="0.001" step="0.001"></td>
    <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('PAXG', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('PAXG', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('PAXG')">نمودار</button>
</td>
</tr>
<tr id="WBTC_row">
    <td data-label="نام ارز">رپد بیت‌کوین (WBTC)</td>
    <td data-label="قیمت (DANA)"><span id="priceWBTC">0</span></td>
    <td data-label="موجودی شما"><span id="balWBTC">0</span></td>
    <td data-label="مقدار معامله"><input type="number" id="amountWBTC" placeholder="تعداد" min="0.001" step="0.001"></td>
    <td data-label="عملیات">
    <button class="btn buy-btn" onclick="trade('WBTC', 'buy')">خرید</button>
    <button class="btn sell-btn" onclick="trade('WBTC', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('WBTC')">نمودار</button>
</td>
</tr>
<tr data-coin="OKB">
<td data-label="نام ارز">او کی بی (OKB)</td>
<td data-label="قیمت (DANA)"><span id="priceOKB">0</span></td>
<td data-label="موجودی شما"><span id="balOKB">0</span></td>
<td data-label="مقدار معامله"><input type="number" id="amountOKB" placeholder="تعداد" min="0.001" step="0.001"></td>
<td data-label="عملیات">
<button class="btn buy-btn" onclick="trade('OKB', 'buy')">خرید</button>
<button class="btn sell-btn" onclick="trade('OKB', 'sell')">فروش</button>
</td>
<td data-label="نمودار">
    <button class="btn chart-btn" onclick="openChart('OKB')">نمودار</button>

      
    </td>
</tr>
</tbody>
        </table>
        <br><br>
    </div>

    <div id="leaderboard" class="section">
        <h1>برترین‌ها</h1>
        <div class="leaderboard">
            <table id="topPlayers"><tr><td>در حال بارگذاری...</td></tr></table>
        </div>
    </div>
    
    <div id="barracks" class="section">
    <h1>پادگان (Barracks)</h1>
    <div class="barracks-info">
        <p>تعداد سربازان شما: <span id="soldierCount">0</span> / <span id="soldierMax">0</span></p>
        <p>تعداد نگهبانان شما: <span id="guardCount">0</span> / <span id="guardMax">0</span></p>
    </div>

    <!-- کارت‌های خرید - ظاهر کاملاً مشابه کارت‌های فروشگاه / محصولات من / کریپتو -->
    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; padding: 20px; max-width: 1200px; margin: 0 auto;">

        <!-- کارت خرید سرباز -->
        <div class="product-card">
            <h2 style="color:#4CAF50;">خرید سرباز</h2>
            <div class="product-info-row">
                <strong>قیمت هر واحد:</strong>
                <span class="value">۱۰۰ داناکوین</span>
            </div>
            <div class="product-info-row">
                <strong>مقدار خرید:</strong>
                <span class="value">
                    <input type="number" id="buySoldierCount" placeholder="تعداد" min="1" style="width:120px; padding:8px; border-radius:8px; border:none; background:#333; color:#fff; text-align:center;">
                </span>
            </div>
            <div class="miner-buttons">
                <button class="btn buy-btn" onclick="buySoldierMultiple()" style="width:100%; height:60px; font-size:18px;">خرید سرباز</button>
            </div>
        </div>

        <!-- کارت خرید نگهبان -->
        <div class="product-card">
            <h2 style="color:#f44336;">خرید نگهبان</h2>
            <div class="product-info-row">
                <strong>قیمت هر واحد:</strong>
                <span class="value">۲۰۰ داناکوین</span>
            </div>
            <div class="product-info-row">
                <strong>مقدار خرید:</strong>
                <span class="value">
                    <input type="number" id="buyGuardCount" placeholder="تعداد" min="1" style="width:120px; padding:8px; border-radius:8px; border:none; background:#333; color:#fff; text-align:center;">
                </span>
            </div>
            <div class="miner-buttons">
                <button class="btn buy-btn" onclick="buyGuardMultiple()" style="width:100%; height:60px; font-size:18px;">خرید نگهبان</button>
            </div>
        </div>

        <!-- کارت خرید خانه سرباز (ظرفیت پادگان) -->
        <div class="product-card">
            <h2 style="color:#00bcd4;">خرید خانه سرباز</h2>
            <div class="product-info-row">
                <strong>قیمت هر خانه:</strong>
                <span class="value">۵,۰۰۰ داناکوین</span>
            </div>
            <div class="product-info-row">
                <strong>ظرفیت اضافه شده:</strong>
                <span class="value">۱۰۰ واحد سرباز</span>
            </div>
            <div class="product-info-row">
                <strong>مقدار خرید:</strong>
                <span class="value">
                    <input type="number" id="buyBarrackSlotCount" placeholder="تعداد" min="1" style="width:120px; padding:8px; border-radius:8px; border:none; background:#333; color:#fff; text-align:center;">
                </span>
            </div>
            <div class="miner-buttons">
                <button class="btn buy-btn" onclick="buyBarrackSlotMultiple()" style="width:100%; height:60px; font-size:18px;">خرید خانه سرباز</button>
            </div>
        </div>

        <!-- کارت خرید خانه نگهبان (ظرفیت نگهبانی) -->
        <div class="product-card">
            <h2 style="color:#9c27b0;">خرید خانه نگهبان</h2>
            <div class="product-info-row">
                <strong>قیمت هر خانه:</strong>
                <span class="value">۱۰,۰۰۰ داناکوین</span>
            </div>
            <div class="product-info-row">
                <strong>ظرفیت اضافه شده:</strong>
                <span class="value">۱۰۰ واحد نگهبان</span>
            </div>
            <div class="product-info-row">
                <strong>مقدار خرید:</strong>
                <span class="value">
                    <input type="number" id="buyGuardSlotCount" placeholder="تعداد" min="1" style="width:120px; padding:8px; border-radius:8px; border:none; background:#333; color:#fff; text-align:center;">
                </span>
            </div>
            <div class="miner-buttons">
                <button class="btn buy-btn" onclick="buyGuardSlotMultiple()" style="width:100%; height:60px; font-size:18px;">خرید خانه نگهبان</button>
            </div>
        </div>

    </div>
</div>

    <div id="attack" class="section">
        <h1>حمله (Raid)</h1>
        <p style="font-size:20px; color:#f44336; font-weight:bold;">سربازان آماده نبرد: <span id="attackSoldierCount">0</span></p>
        <p style="font-size:16px; color:#ff9800; margin-bottom:30px;">محدودیت حمله: هر 1 دقیقه یکبار</p>
        
        <div id="attackTimer" style="display:none; color:#00bcd4; font-size:24px; margin-bottom:30px;">
            زمان باقیمانده تا حمله بعدی: <span id="timerCountdown"></span>
        </div>

        <div class="admin-action">
            <input type="text" id="targetUsername" placeholder="نام کاربری هدف" style="width:250px;"><br>
            <input type="number" id="attackSoldierAmount" placeholder="تعداد سرباز اعزامی" min="1" style="width:250px;"><br>
            <button class="btn" id="performAttackBtn" style="background:#f44336;" onclick="performAttack()">حمله کن!</button>
        </div>
    </div>
    
    <div id="transfer" class="section">
        <h1>انتقال داناکوین</h1>
        <p style="font-size:25px;">موجودی شما: <span id="transferBalance">0</span> داناکوین</p>
        
        <div class="admin-action">
            <input type="text" id="transferTargetUser" placeholder="نام کاربری گیرنده" style="width:250px;"><br>
            <input type="number" id="transferAmount" placeholder="مقدار داناکوین" min="1" style="width:250px;"><br>
            <button class="btn" style="background:#9c27b0;" onclick="performTransfer()">انتقال</button>
        </div>
    </div>

    <div id="news" class="section">
        <h1>اخبار و رویدادهای بازی</h1>
        <div class="news-list" style="max-width: 600px; margin: 0 auto;">
            <table id="newsTable">
                <tr><th>زمان</th><th>پیام</th></tr>
            </table>
        </div>
    </div>

    

<div id="appModal" class="modal" onclick="closeModal(event)">
    <div class="modal-content">
        <p id="modalMessage"></p>
        <button class="btn" onclick="closeModal()">متوجه شدم</button>
    </div>
</div>

<script>

// جستجوی زنده در صرافی
document.getElementById('cryptoSearch')?.addEventListener('input', function(e) {
    const query = e.target.value.trim().toUpperCase();
    const rows = document.querySelectorAll('#exchangeTable tr');
    let found = false;

    rows.forEach(row => {
        const coinName = row.textContent || '';
        const coinSymbol = row.id.replace('_row', ''); // مثلاً YFI_row → YFI
        
        if (query === '' || coinName.includes(query) || coinSymbol.includes(query)) {
            row.style.display = '';
            found = true;
        } else {
            row.style.display = 'none';
        }
    });

    // نمایش پیام "یافت نشد" اگر نتیجه‌ای نبود
    let noResults = document.getElementById('noResultsMsg');
    if (!noResults && query !== '') {
        if (!found) {
            noResults = document.createElement('div');
            noResults.id = 'noResultsMsg';
            noResults.className = 'no-results';
            noResults.innerHTML = `هیچ ارزی با "<strong>${e.target.value}</strong>" پیدا نشد`;
            document.querySelector('#exchange').insertBefore(noResults, document.querySelector('.crypto-table'));
        }
    } else if (noResults) {
        if (found || query === '') {
            noResults.remove();
        } else {
            noResults.innerHTML = `هیچ ارزی با "<strong>${e.target.value}</strong>" پیدا نشد`;
        }
    }
});

let currentUser = localStorage.getItem('currentUser');
let isAdmin = false;
let users = {};
let prices = {};
let news = [];
let sponsorsRefreshInterval = null; // ← جدید: برای polling خودکار در بخش اسپانسرها
let globalData = {}; // برای نگهداری totalMinersBought و چیزهای سراسری
let priceUpdateInterval;
let attackTimerInterval;
const ATTACK_COOLDOWN = 60 * 1000; // ۱ دقیقه
let lastCheckedNewsTimestamp = parseInt(localStorage.getItem('lastCheckedNewsTimestamp')) || 0;

// متغیرهای ضد اتوکلیکر
let clickTimestamps = [];     // زمان هر کلیک
let mineBanInterval = null;   // تایمر نمایش بن

// تابع مدیریت وضعیت زنگوله خبر
function checkUnreadNews() {
    const bell = document.getElementById('newsBell');
    const badge = document.getElementById('newsBadge');
    
    if (!currentUser) {
        bell.style.display = 'none';
        return;
    }
    
    bell.style.display = 'flex'; // نمایش زنگوله برای کاربران وارد شده

    // فیلتر اخبار مرتبط (عمومی یا مختص کاربر)
    const relevantNews = news.filter(n => !n.target || n.target === currentUser);

    // شمارش اخبار جدیدتر از آخرین باری که کاربر دید
    // توجه: news از سرور می‌آید و جدیدترین خبر در انتهای آرایه است.
    const unreadCount = relevantNews.filter(n => n.timestamp > lastCheckedNewsTimestamp).length;

    if (unreadCount > 0) {
        badge.textContent = unreadCount.toLocaleString();
        badge.style.display = 'block';
        if (!bell.classList.contains('shake-bell')) {
            bell.classList.add('shake-bell');
        }
    } else {
        badge.style.display = 'none';
        bell.classList.remove('shake-bell');
    }
}

// تابع نمایش آخرین اخبار و تنظیم زمان بازدید
function loadNews() {
    const table = document.getElementById('newsTable');
    
    // فیلتر اخبار مرتبط (عمومی یا مختص کاربر)
    const relevantNews = news.filter(n => !n.target || n.target === currentUser).reverse(); // نمایش جدیدترین در بالا

    let html = `<tr><th>زمان</th><th>پیام</th></tr>`;
    
    // بهینه‌سازی: فقط ۵۰ خبر آخر را نمایش دهید
    const newsToShow = relevantNews.slice(0, 50);

    newsToShow.forEach(item => {
        const date = new Date(item.timestamp);
        const timeStr = date.toLocaleTimeString('fa-IR', {hour: '2-digit', minute:'2-digit'});
        const dateStr = date.toLocaleDateString('fa-IR');
        
        // اگر پیام شامل ** بود، Bold نمایش داده شود
        const message = item.message.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
        
        html += `<tr><td>${dateStr} ${timeStr}</td><td>${message}</td></tr>`;
    });
    
    table.innerHTML = html;
    
    // پس از مشاهده اخبار، زمان آخرین بازدید را به‌روز می‌کنیم
    if (news.length > 0) {
        // از زمان آخرین خبر استفاده می‌کنیم
        const latestNewsTimestamp = news[news.length - 1].timestamp;
        localStorage.setItem('lastCheckedNewsTimestamp', latestNewsTimestamp);
        lastCheckedNewsTimestamp = latestNewsTimestamp;
    } else {
        localStorage.setItem('lastCheckedNewsTimestamp', Date.now());
        lastCheckedNewsTimestamp = Date.now();
    }
    
    // به‌روزرسانی وضعیت زنگوله
    checkUnreadNews(); 
}


// ---------------------------------------------------------------------------------
// توابع عمومی
// ---------------------------------------------------------------------------------
function showModal(message) {
    document.getElementById('modalMessage').innerHTML = message;
    document.getElementById('appModal').style.display = 'flex';
}

function closeModal(event) {
    if (event && event.target.id === 'appModal') {
        document.getElementById('appModal').style.display = 'none';
    } else if (!event) {
        document.getElementById('appModal').style.display = 'none';
    }
}

// تابع اصلی لود داده‌ها از سرور
async function loadData() {
    const res = await fetch('', {
        method:'POST', 
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'load', username: currentUser}) 
    });
    const r = await res.json();
    globalData = r; // تمام داده‌های سراسری (مثل totalMinersBought) اینجا هست
    
    if (r.users) users = r.users;
    if (r.prices) prices = r.prices;
    if (r.news) news = r.news;
    if (r.sponsors) sponsors = r.sponsors; // ← خط جدید: آرایه اسپانسرها از سرور گرفته می‌شود

    if (r.currentUserStatus) {
         // بررسی مسدودسازی
        if (currentUser && r.currentUserStatus.is_banned) {
            // اگر کاربر مسدود شده، لاگ اوت کرده و پیغام بده
            if (!users[currentUser].is_banned) {
                 showModal('حساب شما توسط ادمین مسدود شد.');
                 users[currentUser].is_banned = true; // به‌روزرسانی محلی
            }
            document.getElementById('logoutBtn').click();
            return;
        }
        // اطمینان از به‌روزرسانی isAdmin در صورت تغییر توسط ادمین
        isAdmin = r.currentUserStatus.is_admin || false;
    }
}

// تابع کمکی برای ذخیره داده‌های کاربر فعلی در سرور
async function saveData() {
    if (!currentUser || isAdmin) return; // ادمین داده‌ها را ذخیره نمی‌کند
    
    // قبل از ذخیره، مطمئن شوید مسدود نشده‌اید (بررسی امنیتی اضافی)
    if (users[currentUser] && users[currentUser].is_banned) {
        showModal('حساب شما مسدود است. عملیات لغو شد.'); 
        return;
    }
    
    const userData = users[currentUser];
    const res = await fetch('', { 
        method:'POST', 
        headers:{'Content-Type':'application/json'}, 
        body:JSON.stringify({action:'save', username: currentUser, userData}) 
    });
    const r = await res.json();
    if (!r.success) {
        showModal('خطا در ذخیره داده‌ها: ' + (r.msg || 'نامشخص'));
    }
}

// ---------------------------------------------------------------------------------
// توابع ورود و خروج
// ---------------------------------------------------------------------------------
async function register() {
    const username = document.getElementById('regUsername').value.trim();
    const pass = document.getElementById('regPass').value;

    // چک خالی بودن فیلدها
    if (username === '' || pass === '') {
        showModal('نام کاربری و رمز عبور الزامی است.');
        return;
    }

    // چک تیک قوانین و مقررات (جدید)
    if (!document.getElementById('agreeRules').checked) {
        showModal('لطفاً ابتدا قوانین و مقررات را بخوانید و تیک قبول را بزنید.');
        return;
    }

    // نمایش لودینگ (اگر در پروژه‌تون دارید، نگهش دارید)
    // document.getElementById('loadingOverlay').classList.remove('hidden');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'register',
            username: username,
            pass: pass
        })
    });

    const data = await res.json();

    // مخفی کردن لودینگ (اگر دارید)
    // document.getElementById('loadingOverlay').classList.add('hidden');

    if (data.success) {
        // ثبت‌نام موفق → ورود خودکار
        currentUser = username;
        isAdmin = data.is_admin || false;
        setupUser();
        showSection(getMainDashboard());
        showModal('ثبت‌نام با موفقیت انجام شد! خوش آمدید 🚀');
        await loadData(); // بروزرسانی داده‌ها
    } else {
        showModal(data.msg || 'خطایی رخ داد. دوباره امتحان کنید.');
    }
}

async function login() {
    const u = document.getElementById('regUsername').value.trim();
    const p = document.getElementById('regPass').value;
   
    const res = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'login', username: u, pass: p})
    });
   
    const r = await res.json();
    if (r.success) {
        localStorage.setItem('currentUser', u);
        currentUser = u;
        isAdmin = r.is_admin || false;
        await loadData(); // لود داده‌های جدید
        setupUser();
        showSection(getMainDashboard()); // ← تغییر مهم: حالا هلپر مستقیم به helperDashboard میره
    } else {
        showModal('خطا در ورود: ' + r.msg);
    }
}

function logout() {
    localStorage.removeItem('currentUser');
    currentUser = null;
    isAdmin = false;
    stopPriceUpdateChecker();
    if (attackTimerInterval) clearInterval(attackTimerInterval);
    document.getElementById('welcome').classList.add('active');
    document.getElementById('dashboard').classList.remove('active');
    document.getElementById('adminDashboard').classList.remove('active');
    document.getElementById('adminDashboardBtn').style.display = 'none';
    document.getElementById('dashboardBtn').style.display = 'none';
    document.getElementById('logoutBtn').style.display = 'none';
    document.getElementById('newsBell').style.display = 'none';
    showSection('welcome');
}

function setupUser() {
    document.getElementById('logoutBtn').style.display = 'block';
    document.getElementById('dashboardBtn').style.display = 'block';

    const dashboardBtn = document.getElementById('dashboardBtn');

    // فقط متن دکمه رو تغییر بده، onclick رو دست نزن (در HTML اصلی به getMainDashboard() اشاره داره)
    if (isAdmin) {
        dashboardBtn.textContent = 'داشبورد ادمین';
    } else if (users[currentUser]?.is_helper) {
        dashboardBtn.textContent = 'داشبورد هلپر';
    } else {
        dashboardBtn.textContent = 'داشبورد';
    }

    // نمایش دکمه ایجاد هلپر فقط برای ادمین
    if (isAdmin) {
        document.getElementById('helperCreateButton').style.display = 'block';
    }
}



function loadUserData() {

// ====================== بروزرسانی بخش "کل دارایی" اگر باز باشه ======================
if (document.getElementById('portfolio') && document.getElementById('portfolio').classList.contains('active')) {
    const u = users[currentUser];

    // موجودی و گردش مالی
    document.getElementById('portBalance').textContent = (u.balance || 0).toLocaleString();
    const totalEarned = (u.totalEarned || 0) + (u.balance || 0);
    document.getElementById('portTotalEarned').textContent = totalEarned.toLocaleString();

    // آمار خرید و فروش
    document.getElementById('portTotalBought').textContent = (u.totalCryptoBought || 0).toLocaleString();
    document.getElementById('portTotalSold').textContent = (u.totalCryptoSold || 0).toLocaleString();

    // رمز ارزها
    let cryptoHtml = '';
    let totalCryptoValue = 0;
    for (const [coin, amount] of Object.entries(u.crypto || {})) {
        if (amount > 0) {
            const value = amount * (prices[coin] || 0);
            totalCryptoValue += value;
            cryptoHtml += `<p><strong>${coin}:</strong> ${amount.toLocaleString('en-US', {maximumFractionDigits: 8})} واحد (≈ ${Math.floor(value).toLocaleString()} داناکوین)</p>`;
        }
    }
    if (cryptoHtml === '') cryptoHtml = '<p style="color:#666;">شما هنوز هیچ رمز ارزی ندارید.</p>';
    document.getElementById('portCryptoList').innerHTML = cryptoHtml;
    document.getElementById('portCryptoValue').textContent = Math.floor(totalCryptoValue).toLocaleString();

    // وضعیت نظامی
    document.getElementById('portBarrackSlots').textContent = (u.barrackSlots || 0).toLocaleString();
    document.getElementById('portMaxSoldiers').textContent = ((u.barrackSlots || 0) * 100).toLocaleString();
    document.getElementById('portSoldiers').textContent = (u.soldiers || 0).toLocaleString();

    document.getElementById('portGuardSlots').textContent = (u.guardSlots || 0).toLocaleString();
    document.getElementById('portMaxGuards').textContent = ((u.guardSlots || 0) * 100).toLocaleString();
    document.getElementById('portGuards').textContent = (u.guards || 0).toLocaleString();
}

if (!currentUser || !users[currentUser]) return;
const u = users[currentUser];

// موجودی داناکوین
document.querySelectorAll('#balance, #mineBalance, #transferBalance').forEach(el => el.textContent = (u.balance || 0).toLocaleString());

// محاسبه ارزش کل دارایی
let totalValue = u.balance || 0;
for (const [coin, balance] of Object.entries(u.crypto)) {
    totalValue += (balance || 0) * (prices[coin] || 0);
}
document.getElementById('totalBalance').textContent = Math.floor(totalValue).toLocaleString();

updateCryptoBalances();

// نمایش نام کاربری در داشبورد عادی
const usernameDisplay = document.getElementById('usernameDisplay');
if (usernameDisplay) {
    usernameDisplay.textContent = currentUser;
}

// نمایش نام کاربری + (هلپر) در داشبورد اختصاصی هلپر
const helperUsernameDisplay = document.getElementById('helperUsernameDisplay');
if (helperUsernameDisplay) {
    helperUsernameDisplay.textContent = currentUser + " (هلپر)";
}

}

let pendingClicks = 0;           // تعداد کلیک‌های در صف
let isSendingBatch = false;      // آیا در حال ارسال دسته‌ای هستیم؟
let lastBatchTime = 0;           // زمان آخرین ارسال دسته‌ای
const BATCH_INTERVAL = 400;      // هر ۴۰۰ میلی‌ثانیه یه بار ارسال کن (بهینه برای موبایل)


// ---------------------------------------------------------------------------------
// توابع ماینینگ
// ---------------------------------------------------------------------------------
async function mineClick(event) {
    if (isAdmin) return;

    // افکت دکمه (لرزش) — فوری نشون بده
    const btn = event.currentTarget;
    btn.classList.add('pulse');
    setTimeout(() => btn.classList.remove('pulse'), 400);

    // موقعیت کلیک برای ذره
    let clickX, clickY;
    if (event.touches) {
        clickX = event.touches[0].clientX;
        clickY = event.touches[0].clientY;
    } else {
        clickX = event.clientX;
        clickY = event.clientY;
    }

    // قدرت کلیک فعلی
    const power = users[currentUser]?.click_power || 5;

    // ۱. ذره فوری نشون بده
    createParticle(clickX, clickY, power);

    // ۲. موجودی رو لحظه‌ای بالا ببر (پیش‌بینی شده)
    users[currentUser].balance = (users[currentUser].balance || 0) + power;
    document.querySelectorAll('#balance, #mineBalance, #transferBalance').forEach(el => {
        el.textContent = Number(users[currentUser].balance).toLocaleString();
    });

    // ۳. کلیک رو به صف اضافه کن
    pendingClicks++;
    clickTimestamps.push(Date.now()); // ثبت زمان کلیک برای تشخیص اتوکلیکر

    // ۴. ارسال هوشمند (هر ۴۰۰ms یا وقتی ۵ تا کلیک جمع شد)
    const now = Date.now();
    if (!isSendingBatch && (pendingClicks >= 5 || now - lastBatchTime > BATCH_INTERVAL)) {
        sendClickBatch();
    }
}
async function sendClickBatch() {
    if (isSendingBatch || pendingClicks === 0) return;

    isSendingBatch = true;
    const clicksToSend = pendingClicks;
    pendingClicks = 0;
    lastBatchTime = Date.now();
            clickTimestamps = clickTimestamps.slice(-clicksToSend); // فقط کلیک‌های این بسته بمونه

    try {
        const res = await fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({
            action: 'mine_click_batch',
            username: currentUser,
            count: clicksToSend,
            timestamps: clickTimestamps.slice(-clicksToSend) // ارسال زمان کلیک‌ها
        })
        });

        const r = await res.json();

                if (r.banned) {
            mineBanEnd = r.ban_end || 0;
            updateMineBanDisplay();
            showModal(r.msg);
            pendingClicks = 0;
            clickTimestamps = [];
            return;
        }

        if (r.success && r.newBalance !== undefined) {
            // موجودی دقیق از سرور رو اعمال کن
            users[currentUser].balance = r.newBalance;
            document.querySelectorAll('#balance, #mineBalance, #transferBalance').forEach(el => {
                el.textContent = Number(r.newBalance).toLocaleString();
            });
        }
    } catch (err) {
        // اگه اینترنت قطع شد، کلیک‌ها رو برگردون تا بعداً ارسال بشه
        pendingClicks += clicksToSend;
        console.log("اتصال قطع شد، کلیک‌ها در صف ماندند...");
    } finally {
        isSendingBatch = false;
        // اگه کلیک جدید اومده، دوباره امتحان کن
        if (pendingClicks > 0) {
            setTimeout(sendClickBatch, 200);
        }
    }
}




// تابع جدید ساخت ذره — حرفه‌ای و دقیق از محل کلیک
function createParticle(x, y, value) {
    const particle = document.createElement('div');
    particle.classList.add('particle');

    // نمایش بهتر اعداد بزرگ (مثلاً +1.2M یا +850K)
    let displayValue;
    if (value >= 1000000) {
        displayValue = `+${(value/1000000).toFixed(1)}M`;
    } else if (value >= 1000) {
        displayValue = `+${(value/1000).toFixed(1)}K`;
    } else {
        displayValue = `+${value.toLocaleString()}`;
    }
    particle.textContent = displayValue;

    // موقعیت دقیق وسط کلیک
    particle.style.left = x + 'px';
    particle.style.top = y + 'px';
    particle.style.transform = 'translate(-50%, -50%)';

    // اندازه ذره بر اساس مقدار (هر چی بیشتر، بزرگ‌تر و درخشان‌تر)
    const baseSize = 32;
    const sizeBoost = Math.min(value / 1000, 40); // حداکثر تا ۴۰پیکسل اضافه
    particle.style.fontSize = (baseSize + sizeBoost) + 'px';

    document.body.appendChild(particle);

    // حذف بعد از انیمیشن
    setTimeout(() => particle.remove(), 2000);
}
function loadMine() {
    if (!currentUser || !users[currentUser]) return;
    const u = users[currentUser];
    const level = u.click_level || 0;
    const displayLevel = level + 1;                    // نمایش از سطح ۱ شروع می‌شه
    const power = u.click_power || 5;

    document.getElementById('mineMultiplier').textContent = displayLevel;
    document.getElementById('mineClickValue').textContent = power.toLocaleString() + ' داناکوین';
    document.getElementById('upgradeCost').textContent = (u.upgradeCost || 500).toLocaleString() + ' داناکوین';
}

async function upgradeMultiplier() {
    if (isAdmin) return showModal('ادمین نمی‌تواند ارتقا دهد.');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({action: 'upgrade_click', username: currentUser})
    });
    const r = await res.json();

    if (r.success) {
        // بروزرسانی موجودی
        document.querySelectorAll('#balance, #mineBalance, #transferBalance').forEach(el => {
            el.textContent = Number(r.newBalance).toLocaleString();
        });

        // فوراً صفحه ماین رو آپدیت کن — بدون رفرش!
        document.getElementById('mineClickValue').textContent = Number(r.newPower).toLocaleString() + ' داناکوین';
        document.getElementById('upgradeCost').textContent = Number(r.newCost).toLocaleString() + ' داناکوین';
        document.getElementById('mineMultiplier').textContent = r.newLevel;

        // بروزرسانی داده‌های محلی برای کلیک بعدی
        users[currentUser].balance = r.newBalance;
        users[currentUser].click_power = r.newPower;
        users[currentUser].upgradeCost = r.newCost;
        users[currentUser].click_level = r.newLevel - 1;

        showModal(`ارتقا موفق! حالا هر کلیک ${r.newPower.toLocaleString()} داناکوین میده!`);

        // افکت خوشحال‌کننده
        const upgradeBtn = document.querySelector('#mine .buy-btn');
        if (upgradeBtn) {
            upgradeBtn.classList.add('pulse');
            setTimeout(() => upgradeBtn.classList.remove('pulse'), 600);
        }
    } else {
        showModal(r.msg || 'خطا در ارتقا');
        await loadData();
        loadUserData();
        loadMine();
    }
}

// ---------------------------------------------------------------------------------
// توابع صرافی
// ---------------------------------------------------------------------------------

function openChart(coin) {
    const symbols = {
        'BTC': 'BINANCE:BTCUSDT',
        'ETH': 'BINANCE:ETHUSDT',
        'BNB': 'BINANCE:BNBUSDT',
        'SOL': 'BINANCE:SOLUSDT',
        'TAO': 'BINANCE:TAOUSDT',
        'AAVE': 'BINANCE:AAVEUSDT',
        'BCH': 'BINANCE:BCHUSDT',
        'ZEC': 'BINANCE:ZECUSDT',
        'XMR': 'KRAKEN:XMRUSD',
        'LTC': 'BINANCE:LTCUSDT',
        'YFI': 'BINANCE:YFIUSDT',
        'PAXG': 'BINANCE:PAXGUSDT',
        'WBTC': 'BINANCE:WBTCUSDT',
        'OKB': 'OKX:OKBUSDT'

    };

    const symbol = symbols[coin] || 'BINANCE:BTCUSDT';
    const url = `https://www.tradingview.com/chart/?symbol=${symbol}&theme=dark&style=1&timezone=Asia/Tehran`;

    window.open(url, '_blank');
}
// نمایش قیمت‌های فعلی
function updatePriceDisplay() {
    document.getElementById('priceBTC').textContent = (prices.BTC || 0).toLocaleString();
    document.getElementById('priceETH').textContent = (prices.ETH || 0).toLocaleString();
    document.getElementById('priceBNB').textContent = (prices.BNB || 0).toLocaleString();
    document.getElementById('priceSOL').textContent = (prices.SOL || 0).toLocaleString();
   document.getElementById('priceTAO').textContent = (prices.TAO || 0).toLocaleString();
document.getElementById('priceAAVE').textContent = (prices.AAVE || 0).toLocaleString();
document.getElementById('priceBCH').textContent = (prices.BCH || 0).toLocaleString();
document.getElementById('priceZEC').textContent = (prices.ZEC || 0).toLocaleString();
document.getElementById('priceXMR').textContent = (prices.XMR || 0).toLocaleString();
document.getElementById('priceLTC').textContent = (prices.LTC || 0).toLocaleString();
document.getElementById('priceYFI').textContent = (prices.YFI || 0).toLocaleString();
document.getElementById('pricePAXG').textContent = (prices.PAXG || 0).toLocaleString();
document.getElementById('priceWBTC').textContent = (prices.WBTC || 0).toLocaleString();
document.getElementById('priceOKB').textContent = (prices.OKB || 0).toLocaleString();
}

// نمایش موجودی‌های رمز ارز
function updateCryptoBalances() {
    const u = users[currentUser];
    
    document.getElementById('balBTC').textContent = (u.crypto.BTC || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balETH').textContent = (u.crypto.ETH || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balBNB').textContent = (u.crypto.BNB || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balSOL').textContent = (u.crypto.SOL || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balTAO').textContent = (u.crypto.TAO || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balAAVE').textContent = (u.crypto.AAVE || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balBCH').textContent = (u.crypto.BCH || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balZEC').textContent = (u.crypto.ZEC || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balXMR').textContent = (u.crypto.XMR || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balLTC').textContent = (u.crypto.LTC || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    document.getElementById('balYFI').textContent = (u.crypto.YFI || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
document.getElementById('balPAXG').textContent = (u.crypto.PAXG || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
document.getElementById('balWBTC').textContent = (u.crypto.WBTC || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
document.getElementById('balOKB').textContent = (u.crypto.OKB || 0).toLocaleString('en-US', { maximumFractionDigits: 8 });
    
    // به‌روزرسانی صفحه موجودی
    const cryptoBalancesDiv = document.getElementById('cryptoBalances');
    cryptoBalancesDiv.innerHTML = '';
    for (const [coin, balance] of Object.entries(u.crypto)) {
        if (balance > 0) {
            const price = prices[coin] || 0;
            const value = balance * price;
            cryptoBalancesDiv.innerHTML += `<p>${coin}: ${balance.toLocaleString()} (ارزش تقریبی: ${value.toLocaleString()} داناکوین)</p>`;
        }
    }
    if (cryptoBalancesDiv.innerHTML === '') {
        cryptoBalancesDiv.innerHTML = '<p style="color:#aaa;">شما هیچ رمز ارزی ندارید.</p>';
    }
}

// چکر به‌روزرسانی قیمت (هر ۱۰ ثانیه)
function startPriceUpdateChecker() {
    updatePriceDisplay();
    updateCryptoBalances();
    if (priceUpdateInterval) clearInterval(priceUpdateInterval);
    priceUpdateInterval = setInterval(async () => {
        await loadData(); // لود داده‌ها برای دریافت آخرین قیمت‌ها
        loadUserData(); // به‌روزرسانی موجودی کاربر و بالانس کریپتو
        updatePriceDisplay(); // نمایش قیمت‌های جدید
    }, 10000); // 10 ثانیه یکبار
}

function stopPriceUpdateChecker() {
    if (priceUpdateInterval) {
        clearInterval(priceUpdateInterval);
        priceUpdateInterval = null;
    }
}

// تابع خرید و فروش
async function trade(coin, action) {
    if (isAdmin) return showModal('ادمین نمی‌تواند معامله کند.');
    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است. عملیات لغو شد.');

    const u = users[currentUser];
    const amountInput = document.getElementById(`amount${coin}`);
    let amount = parseFloat(amountInput.value);

    // چک جدید: فقط اعداد صحیح و حداقل ۱ مجاز است
    if (isNaN(amount) || amount < 1 || !Number.isInteger(amount)) {
        showModal('فقط خرید/فروش با عدد صحیح و حداقل ۱ مجاز است!');
        amountInput.style.border = "2px solid red";
        setTimeout(() => amountInput.style.border = "", 2000);
        return;
    }

    const price = prices[coin];
    if (!price || price <= 0) return showModal('قیمت این ارز هنوز بارگذاری نشده است.');

    const costOrRevenue = amount * price;

    if (action === 'buy') {
        const totalCost = Math.ceil(costOrRevenue); // گرد به بالا

        if ((u.balance || 0) >= totalCost) {
            u.balance -= totalCost;
            u.crypto[coin] = (u.crypto[coin] || 0) + amount;
            u.totalCryptoBought = (u.totalCryptoBought || 0) + amount;

            await saveData();
            showModal(`خرید ${amount.toLocaleString()} واحد ${coin} با هزینه ${totalCost.toLocaleString()} داناکوین موفقیت‌آمیز بود.`);
        } else {
            showModal(`داناکوین کافی نیست! هزینه مورد نیاز: ${totalCost.toLocaleString()}`);
        }
    } 

    else if (action === 'sell') {
        if ((u.crypto[coin] || 0) < amount) {
            showModal(`موجودی ${coin} کافی نیست! موجودی فعلی: ${(u.crypto[coin] || 0).toLocaleString()}`);
            return;
        }

        const totalRevenue = Math.floor(costOrRevenue); // گرد به پایین

        u.crypto[coin] -= amount;
        u.totalCryptoSold = (u.totalCryptoSold || 0) + amount;
        // پاک کردن مقادیر خیلی کوچک (مثلاً 1e-15)
        if (u.crypto[coin] < 0.0001) u.crypto[coin] = 0;

        u.balance = (u.balance || 0) + totalRevenue;

        await saveData();
        showModal(`فروش ${amount.toLocaleString()} واحد ${coin} با درآمد ${totalRevenue.toLocaleString()} داناکوین موفقیت‌آمیز بود.`);
    }

    // به‌روزرسانی صفحه
    loadUserData();
       updateCryptoBalances();
    updatePriceDisplay();
    
    // جدید: چک بن ماین اگر بخش ماین فعال باشه
    if (document.getElementById('mine').classList.contains('active')) {
        loadMine();
    }
}

async function buyMiner() {
    if (isAdmin) return showModal('ادمین نمی‌تواند خرید کند.');

    await loadData(); // مطمئن بشیم موجودی به‌روزه
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است.');

    const price = 150000;

    if ((users[currentUser].balance || 0) < price) {
        return showModal('داناکوین کافی نیست! نیاز به ۱۵۰,۰۰۰ داناکوین برای خرید ماشین استخراج دارید.');
    }

    // مرحله تأیید خرید
    const confirmation = confirm(
        `آیا مطمئن هستید که می‌خواهید ماشین استخراج داناکوین را با قیمت ${price.toLocaleString()} داناکوین خریداری کنید؟`
    );

    // اگر کاربر Cancel زد → پیام لغو خرید
    if (!confirmation) {
        return showModal('خرید لغو شد.');
    }

    // اگر تأیید کرد → درخواست خرید به سرور
    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'buy_miner',
            username: currentUser
        })
    });

    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        await loadData();
        loadUserData();
        updateShopStats(); // بروزرسانی تعداد ماشین‌های خریداری شده در فروشگاه
        // اگر می‌خوای بعد از خرید مستقیم بره به بخش "محصولات من":
        // showSection('myproducts');
    }
}
async function collectMiner(minerId) {
    if (isAdmin) return showModal('ادمین نمی‌تواند برداشت کند.');
    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است.');

    const miner = users[currentUser].miners[minerId];
    if (!miner) return showModal('ماشین استخراج یافت نشد!');

    const collectableAmount = miner.collectable || 0;
    if (collectableAmount <= 0) {
        return showModal('هیچ مقداری برای برداشت وجود ندارد.');
    }

    // تشخیص نوع ماشین
    if (miner.type === 'bitcoin') {
        users[currentUser].crypto.BTC = (users[currentUser].crypto.BTC || 0) + collectableAmount;
        showModal(`با موفقیت ${collectableAmount.toFixed(8).replace(/\.?0+$/, '')} بیت‌کوین برداشت شد!`);
    } else if (miner.type === 'litecoin') {
        users[currentUser].crypto.LTC = (users[currentUser].crypto.LTC || 0) + collectableAmount;
        showModal(`با موفقیت ${collectableAmount.toFixed(8).replace(/\.?0+$/, '')} لایت‌کوین برداشت شد!`);
    } else {
        // ماشین داناکوین عادی
        users[currentUser].balance += collectableAmount;
        showModal(`با موفقیت ${collectableAmount.toLocaleString()} داناکوین برداشت شد!`);
    }

    // صفر کردن collectable و بروزرسانی زمان
    miner.collectable = 0;
    const now = Date.now();
    miner.last_collect_time = now;
    miner.next_collect_time = now + 60000;

    // ذخیره تغییرات در سرور
    await saveData();

    // بروزرسانی نمایش
    loadUserData();
    loadMyProducts();
    updateAllMinerTimers();
}

async function upgradeMinerRate(minerId) {
    if (isAdmin) return showModal('ادمین نمی‌تواند ارتقا دهد.');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'upgrade_miner_rate',
            username: currentUser,
            minerId: minerId
        })
    });

    const r = await res.json();

    if (r.success) {
        showModal(r.msg);
        users[currentUser].balance = r.newBalance;
        loadUserData();
        await loadData();
        loadMyProducts();
    } else {
        showModal(r.msg);
    }
}

async function upgradeMinerCapacity(minerId) {
    if (isAdmin) return showModal('ادمین نمی‌تواند ارتقا دهد.');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'upgrade_miner_capacity',
            username: currentUser,
            minerId: minerId
        })
    });

    const r = await res.json();

    if (r.success) {
        showModal(r.msg);
        users[currentUser].balance = r.newBalance;
        loadUserData();
        await loadData();
        loadMyProducts();
    } else {
        showModal(r.msg);
    }
}

async function setCustomName(minerId) {
    if (isAdmin) return showModal('ادمین نمی‌تواند اسم انتخاب کند.');

    const newName = prompt('اسم دلخواه برای ماشین خود وارد کنید (حداکثر ۲۰ کاراکتر):');
    if (!newName || newName.trim() === '') {
        return;
    }
    if (newName.trim().length > 20) {
        return showModal('اسم حداکثر ۲۰ کاراکتر می‌تواند باشد!');
    }

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'set_miner_name',
            username: currentUser,
            minerId: minerId,
            newName: newName.trim()
        })
    });

    const r = await res.json();

    if (r.success) {
        showModal(r.msg);
        await loadData();
        loadMyProducts();
    } else {
        showModal(r.msg || 'خطا در تغییر اسم');
    }
}

async function sellMiner(minerId) {
    if (isAdmin) return showModal('ادمین نمی‌تواند ماشین بفروشد.');

    if (!confirm('آیا مطمئن هستید که می‌خواهید این ماشین را به سایت بفروشید؟\nاین عمل قابل بازگشت نیست!')) {
        return;
    }

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'sell_miner',
            username: currentUser,
            minerId: minerId
        })
    });

    const r = await res.json();

    if (r.success) {
        showModal(r.msg);
        users[currentUser].balance = r.newBalance;
        loadUserData();
        await loadData();
        loadMyProducts(); // کارت ماشین حذف می‌شه
    } else {
        showModal(r.msg || 'خطا در فروش ماشین');
    }
}

// در بخش <script> فایل 2.php

// تابع loadMyProducts() - نسخه اصلاح‌شده
function loadMyProducts() {
    const list = document.getElementById('myMinersList');
    list.innerHTML = '';

    if (!currentUser || !users[currentUser] || !users[currentUser].miners || Object.keys(users[currentUser].miners).length === 0) {
        list.innerHTML = `
            <div style="text-align:center; padding:50px; background:#ffffff11; border-radius:20px; margin:40px auto; max-width:600px;">
                <p style="font-size:24px; color:#ff9800;">شما هنوز هیچ محصولی خریداری نکرده‌اید.</p>
                <p style="font-size:18px; color:#aaa; margin-top:20px;">به فروشگاه سر بزنید و اولین ماشین استخراج خود را بخرید!</p>
            </div>`;
        return;
    }

    const template = document.getElementById('minerTemplate').innerHTML;

    Object.entries(users[currentUser].miners).forEach(([minerId, miner]) => {
        let cardHTML = template.replace(/TEMPLATE_ID/g, minerId);

        // تشخیص نوع ماشین
        const type = miner.type || 'danacoin'; // پیش‌فرض داناکوین
        const isBitcoin = type === 'bitcoin';
        const isLitecoin = type === 'litecoin';

        // عنوان و واحد
        const unitName = isBitcoin ? 'بیت‌کوین' : (isLitecoin ? 'لایت‌کوین' : 'داناکوین');
        const title = isBitcoin ? 'بیت‌کوین' : (isLitecoin ? 'لایت‌کوین' : 'داناکوین');

        // نام سفارشی
        const customName = miner.custom_name || 'اسمی انتخاب نشده است';

        // وضعیت کامل شدن
        const isComplete = (miner.rate_level >= 20) && (miner.capacity_level >= 20);
        const statusText = isComplete ? 'کامل شده' : 'کامل نشده';
        const statusColor = isComplete ? '#4CAF50' : 'red';

        // محاسبه قیمت فروش به سایت
        let baseSellPrice;
        if (isBitcoin) baseSellPrice = 250000;
        else if (isLitecoin) baseSellPrice = 1000;
        else baseSellPrice = 125000; // داناکوین

        const sellPrice = isComplete ? baseSellPrice * 5 : baseSellPrice;

        // جایگزینی مقادیر در کارت
        cardHTML = cardHTML
            .replace('ماشین استخراج <span class="unit-type">داناکوین</span>', `ماشین استخراج <span class="unit-type">${title}</span>`)
            .replace(/<span class="value custom-name">.*?<\/span>/, `<span class="value custom-name">${customName}</span>`)
            .replace('<span class="rate-level">1</span>', `<span class="rate-level">${miner.rate_level || 1}</span>`)
            .replace('<span class="rate">1,000</span>', `<span class="rate">${(miner.rate || 10000).toLocaleString()}</span>`)
            .replace(/<span class="unit">داناکوین<\/span>/g, `<span class="unit">${unitName}</span>`)
            .replace('<span class="capacity-level">1</span>', `<span class="capacity-level">${miner.capacity_level || 1}</span>`)
            .replace('<span class="capacity">5,000</span>', `<span class="capacity">${(miner.capacity || 500000).toLocaleString()}</span>`)
            .replace('<span class="collectable">0 <span class="unit">داناکوین<\/span></span>', `<span class="collectable">${(miner.collectable || 0).toLocaleString()} <span class="unit">${unitName}</span></span>`)
            .replace('در حال محاسبه...', (miner.rate_upgrade_cost || 200000).toLocaleString() + ' داناکوین')
            .replace('در حال محاسبه...', (miner.capacity_upgrade_cost || 2000000).toLocaleString() + ' داناکوین')
            .replace(' 125,000', sellPrice.toLocaleString())
            .replace('کامل نشده', statusText)
            .replace('color:red;', `color:${statusColor};`);

        list.innerHTML += cardHTML;
    });

    updateAllMinerTimers();
}

function updateShopStats() {
    const total = globalData.totalMinersBought || 0;
    if (document.getElementById('totalMinersBought')) {
        document.getElementById('totalMinersBought').textContent = total.toLocaleString();
    }

    const bitcoinTotal = globalData.totalBitcoinMinersBought || 0;
    if (document.getElementById('totalBitcoinMinersBought')) {
        document.getElementById('totalBitcoinMinersBought').textContent = bitcoinTotal.toLocaleString();
    }

    const litecoinTotal = globalData.totalLitecoinMinersBought || 0;
    if (document.getElementById('totalLitecoinMinersBought')) {
        document.getElementById('totalLitecoinMinersBought').textContent = litecoinTotal.toLocaleString();
    }

    if (currentUser && users[currentUser] && users[currentUser].miners) {
        const miners = users[currentUser].miners;

        // ماشین داناکوین معمولی
        const normalCount = Object.values(miners).filter(m => 
            !m.type || (m.type !== 'bitcoin' && m.type !== 'litecoin')
        ).length;
        const elNormal = document.getElementById('ownedNormalMiners');
        if (elNormal) elNormal.textContent = normalCount;

        // ماشین بیت‌کوین
        const bitcoinCount = Object.values(miners).filter(m => m.type === 'bitcoin').length;
        const elBitcoin = document.getElementById('ownedBitcoinMiners');
        if (elBitcoin) elBitcoin.textContent = bitcoinCount;

        // ماشین لایت‌کوین
        const litecoinCount = Object.values(miners).filter(m => m.type === 'litecoin').length;
        const elLitecoin = document.getElementById('ownedLitecoinMiners');
        if (elLitecoin) elLitecoin.textContent = litecoinCount;
    }

}

// ---------------------------------------------------------------------------------
// توابع پادگان
// ---------------------------------------------------------------------------------
function updateBarracksDisplay() {
    if (!currentUser || !users[currentUser]) return;
    const u = users[currentUser];
    const maxSoldiers = (u.barrackSlots || 0) * 100;
    const maxGuards = (u.guardSlots || 0) * 100;
    
    document.getElementById('soldierCount').textContent = (u.soldiers || 0).toLocaleString();
    document.getElementById('guardCount').textContent = (u.guards || 0).toLocaleString();
    document.getElementById('soldierMax').textContent = maxSoldiers.toLocaleString();
    document.getElementById('guardMax').textContent = maxGuards.toLocaleString();
    document.getElementById('attackSoldierCount').textContent = (u.soldiers || 0).toLocaleString();
}

async function buySoldierMultiple() {
    if (isAdmin) return showModal('ادمین نمی‌تواند بخرد.');
    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است. عملیات لغو شد.');

    const count = parseInt(document.getElementById('buySoldierCount').value);
    if (count <= 0 || isNaN(count)) return showModal('تعداد نامعتبر');

    const u = users[currentUser];
    const maxSoldiers = (u.barrackSlots || 0) * 100;
    const currentSoldiers = (u.soldiers || 0);
    const costPerUnit = 100;
    const totalCost = count * costPerUnit;

    if (currentSoldiers + count > maxSoldiers) {
        return showModal(`ظرفیت پادگان شما پر است. حداکثر سرباز قابل خرید: ${(maxSoldiers - currentSoldiers).toLocaleString()}`);
    }

    if ((u.balance || 0) >= totalCost) {
        u.balance -= totalCost;
        u.soldiers = (u.soldiers || 0) + count;
        await saveData();
        loadUserData();
        updateBarracksDisplay();
        showModal(`خرید ${count.toLocaleString()} سرباز با موفقیت انجام شد.`);
    } else showModal(`داناکوین کافی نیست! هزینه مورد نیاز: ${totalCost.toLocaleString()}`);
}

async function buyGuardMultiple() {
    if (isAdmin) return showModal('ادمین نمی‌تواند بخرد.');
    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است. عملیات لغو شد.');

    const count = parseInt(document.getElementById('buyGuardCount').value);
    if (count <= 0 || isNaN(count)) return showModal('تعداد نامعتبر');

    const u = users[currentUser];
    const maxGuards = (u.guardSlots || 0) * 100;
    const currentGuards = (u.guards || 0);
    const costPerUnit = 200;
    const totalCost = count * costPerUnit;

    if (currentGuards + count > maxGuards) {
        return showModal(`ظرفیت نگهبانی شما پر است. حداکثر نگهبان قابل خرید: ${(maxGuards - currentGuards).toLocaleString()}`);
    }

    if ((u.balance || 0) >= totalCost) {
        u.balance -= totalCost;
        u.guards = (u.guards || 0) + count;
        await saveData();
        loadUserData();
        updateBarracksDisplay();
        showModal(`خرید ${count.toLocaleString()} نگهبان با موفقیت انجام شد.`);
    } else showModal(`داناکوین کافی نیست! هزینه مورد نیاز: ${totalCost.toLocaleString()}`);
}

async function buyBarrackSlotMultiple() {
    if (isAdmin) return showModal('ادمین نمی‌تواند بخرد.');
    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است. عملیات لغو شد.');

    const count = parseInt(document.getElementById('buyBarrackSlotCount').value);
    if (count <= 0 || isNaN(count)) return showModal('تعداد نامعتبر');

    const u = users[currentUser];
    const costPerUnit = 5000;
    const totalCost = count * costPerUnit;

    if ((u.balance || 0) >= totalCost) {
        u.balance -= totalCost;
        u.barrackSlots = (u.barrackSlots || 0) + count;
        await saveData();
        loadUserData();
        updateBarracksDisplay();
        showModal(`خرید ${count.toLocaleString()} خانه پادگان با موفقیت انجام شد.`);
    } else showModal(`داناکوین کافی نیست! هزینه مورد نیاز: ${totalCost.toLocaleString()}`);
}

async function buyGuardSlotMultiple() {
    if (isAdmin) return showModal('ادمین نمی‌تواند بخرد.');
    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است. عملیات لغو شد.');

    const count = parseInt(document.getElementById('buyGuardSlotCount').value);
    if (count <= 0 || isNaN(count)) return showModal('تعداد نامعتبر');

    const u = users[currentUser];
    const costPerUnit = 10000;
    const totalCost = count * costPerUnit;

    if ((u.balance || 0) >= totalCost) {
        u.balance -= totalCost;
        u.guardSlots = (u.guardSlots || 0) + count;
        await saveData();
        loadUserData();
        updateBarracksDisplay();
        showModal(`خرید ${count.toLocaleString()} خانه نگهبان با موفقیت انجام شد.`);
    } else showModal(`داناکوین کافی نیست! هزینه مورد نیاز: ${totalCost.toLocaleString()}`);
}

// ---------------------------------------------------------------------------------
// توابع حمله (Attack)
// ---------------------------------------------------------------------------------
function updateAttackTimer() {
    const u = users[currentUser];
    const now = Date.now();
    const lastAttack = u.lastAttackTime || 0;
    const cooldownEnd = lastAttack + ATTACK_COOLDOWN;
    let timeLeft = cooldownEnd - now;
    
    const attackBtn = document.getElementById('performAttackBtn');
    const timerDisplay = document.getElementById('attackTimer');
    const timerCountdown = document.getElementById('timerCountdown');

    if (timeLeft <= 0) {
        attackBtn.disabled = false;
        attackBtn.style.opacity = '1';
        timerDisplay.style.display = 'none';
        if (attackTimerInterval) {
            clearInterval(attackTimerInterval);
            attackTimerInterval = null;
        }
    } else {
        attackBtn.disabled = true;
        attackBtn.style.opacity = '0.5';
        timerDisplay.style.display = 'block';

        const minutes = Math.floor(timeLeft / (60 * 1000));
        const seconds = Math.floor((timeLeft % (60 * 1000)) / 1000);
        
        timerCountdown.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }
}

function updateAttackDisplay() {
    // محدودیت زمانی
    const u = users[currentUser];
    const now = Date.now();
    const lastAttack = u.lastAttackTime || 0;
    const cooldownEnd = lastAttack + ATTACK_COOLDOWN;
    const timeLeft = cooldownEnd - now;

    const attackBtn = document.getElementById('performAttackBtn');
    const timerDisplay = document.getElementById('attackTimer');

    if (timeLeft > 0) {
        attackBtn.disabled = true;
        attackBtn.style.opacity = '0.5';
        timerDisplay.style.display = 'block';
        if (!attackTimerInterval) {
            attackTimerInterval = setInterval(() => {
                updateAttackTimer();
            }, 1000);
        }
    } else {
        attackBtn.disabled = false;
        attackBtn.style.opacity = '1';
        timerDisplay.style.display = 'none';
        if (attackTimerInterval) {
            clearInterval(attackTimerInterval);
            attackTimerInterval = null;
        }
    }
    updateAttackTimer(); // اجرای اولیه برای نمایش فوری
}

async function performAttack() {
    if (isAdmin) return showModal('ادمین نمی‌تواند حمله کند.');
    await loadData(); // لود جدیدترین داده‌های هر دو کاربر
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است. عملیات لغو شد.');

    const target = document.getElementById('targetUsername').value.trim();
    const count = parseInt(document.getElementById('attackSoldierAmount').value);

    if (!users[target] || users[target].is_admin || users[target].is_banned) return showModal('کاربر مورد نظر یافت نشد، ادمین است یا مسدود شده.');
    if (target === currentUser) return showModal('نمی‌توانید به خودتان حمله کنید.');
    if (count <= 0 || isNaN(count)) return showModal('تعداد سرباز نامعتبر');

    let u = users[currentUser];  // اول تعریف کن (let چون بعداً re-assign می‌شه)
    const now = Date.now();
    
    if (now < (u.lastAttackTime || 0) + ATTACK_COOLDOWN) {
        return showModal('هنوز محدودیت ۳۰ دقیقه‌ای شما به پایان نرسیده است.');
    }
    if ((u.soldiers || 0) < count) return showModal('سرباز کافی نیست');

    let attackSuccess = false;
    let loot = 0;
    let targetGuards = users[target].guards || 0;
    
    // کسر سربازهای استفاده شده
    u.soldiers -= count;
    // 📢 تغییر ۱: آپدیت lastAttackTime
    u.lastAttackTime = now; // آپدیت زمان حمله به میلی‌ثانیه فعلی
    
    // 📢 ذخیره وضعیت کاربر مهاجم (سربازان کسر شده و lastAttackTime)
    await saveData(); 

    if (count > targetGuards) {
        attackSuccess = true;
        
        // 📢 لود مجدد برای دریافت جدیدترین موجودی قربانی
        await loadData();
        let updatedTargetUser = users[target];
        if (!updatedTargetUser) return showModal('خطا: اطلاعات کاربر مورد حمله در دسترس نیست.');

        // 📢 اصلاح جدید: re-reference بعد از loadData()
        u = users[currentUser];  // حالا u به object جدید اشاره می‌کنه!

        // 1. محاسبه غنیمت
        loot = Math.floor((updatedTargetUser.balance || 0) * 0.5); 
        
        // 2. موجودی مهاجم اضافه می‌شود (با استفاده از داده‌های لود شده)
        u.balance = (u.balance || 0) + loot; 
        
        // 📢 ذخیره موجودی جدید مهاجم (پس از اضافه کردن loot)
        await saveData(); 
        
        // 3. موجودی قربانی کسر می‌شود
        updatedTargetUser.balance = (updatedTargetUser.balance || 0) - loot;
        
        // 4. نگهبانان قربانی به اندازه سربازان استفاده شده تا سقف نگهبانان کسر می‌شوند.
        const guardsLost = Math.min(targetGuards, count); 
        updatedTargetUser.guards = (updatedTargetUser.guards || 0) - guardsLost;
        
        // 5. ساخت پیام‌ها و ثبت خبر
        const attackerMsg = `حمله شما به **${target}** موفق بود! شما **${loot.toLocaleString()}** داناکوین غارت کردید.`;
        const targetMsg = `کاربر **${currentUser}** به شما حمله کرد و **${loot.toLocaleString()}** داناکوین و **${guardsLost.toLocaleString()}** نگهبان از دست دادید! 😔`;
        showModal(attackerMsg);

        // 6. اضافه کردن خبر
        await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'addNews', message: attackerMsg, targetUser: currentUser}) });
        await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'addNews', message: targetMsg, targetUser: target}) });

        // 7. 📢 ذخیره وضعیت کاربر قربانی (بعد از کسر نگهبانان)
        await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'save', username: target, userData: updatedTargetUser}) });

    } else {
        // حمله ناموفق
        // سربازان استفاده شده برابر با نگهبانان هستند. نگهبانان قربانی به اندازه سربازان مهاجم کسر می‌شوند.
        const guardsLost = Math.min(targetGuards, count); // تعداد سربازان استفاده شده است
        
        // 📢 لود مجدد برای دریافت جدیدترین داده‌های قربانی
        await loadData();
        let updatedTargetUser = users[target];
        if (!updatedTargetUser) return showModal('خطا: اطلاعات کاربر مورد حمله در دسترس نیست.');

        // 📢 اصلاح جدید: re-reference بعد از loadData() برای ایمنی
        updatedTargetUser = users[target];  // حالا به object جدید اشاره می‌کنه

        // 1. کسر نگهبانان
        updatedTargetUser.guards = (updatedTargetUser.guards || 0) - guardsLost;

        // 2. ساخت پیام و نمایش
        const attackerMsg = `حمله شما به **${target}** ناموفق بود. نگهبانان او جلوی شما را گرفتند.`;
        const targetMsg = `کاربر **${currentUser}** به شما حمله کرد اما حمله ناموفق بود. شما **${guardsLost.toLocaleString()}** نگهبان از دست دادید. 💪`;
        showModal(attackerMsg);

        // 3. اضافه کردن خبر
        await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'addNews', message: attackerMsg, targetUser: currentUser}) });
        await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'addNews', message: targetMsg, targetUser: target}) });

        // 4. 📢 ذخیره وضعیت کاربر قربانی (بعد از کسر نگهبانان)
        await fetch('', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'save', username: target, userData: users[target]}) });
    }

    // به‌روزرسانی نمایشگر
    loadUserData(); 
    updateBarracksDisplay();
    updateAttackDisplay();
}

// ---------------------------------------------------------------------------------
// توابع انتقال
// ---------------------------------------------------------------------------------
async function performTransfer() {
    if (isAdmin) return showModal('ادمین نمی‌تواند انتقال دهد.');
    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است. عملیات لغو شد.');

    const receiver = document.getElementById('transferTargetUser').value.trim();
    const amount = parseInt(document.getElementById('transferAmount').value);

    if (amount <= 0 || isNaN(amount)) return showModal('مقدار نامعتبر.');
    if (users[currentUser].balance < amount) return showModal('موجودی کافی نیست.');
    if (receiver.length < 3 || !users[receiver]) return showModal('کاربر گیرنده یافت نشد.');
    if (receiver === currentUser) return showModal('نمی‌توانید به خودتان انتقال دهید.');

    const res = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            action:'transfer', 
            sender: currentUser, 
            receiver: receiver, 
            amount: amount
        })
    });

    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        // به‌روزرسانی داده‌های محلی پس از موفقیت
        await loadData(); 
        loadUserData();
        
    }
}

// ---------------------------------------------------------------------------------
// توابع ادمین
// ---------------------------------------------------------------------------------
async function toggleBan(shouldBan) {
    if (!currentUser || !isAdmin) return showModal('شما اجازه انجام این عملیات را ندارید.');

    const targetUser = document.getElementById('banTargetUser').value.trim();
    if (targetUser.length === 0) return showModal('لطفاً نام کاربری را وارد کنید.');

    const res = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            action:'toggleBan', 
            admin_user: currentUser, 
            targetUser: targetUser, 
            shouldBan: shouldBan
        })
    });
    
    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        await loadData();
        loadAdminUserList();
        document.getElementById('banTargetUser').value = '';
    }
}

async function giveCoin() {
    if (!currentUser || !isAdmin) return showModal('شما اجازه انجام این عملیات را ندارید.');

    const targetUser = document.getElementById('coinTargetUser').value.trim();
    const amount = parseInt(document.getElementById('coinAmount').value);

    if (targetUser.length === 0) return showModal('لطفاً نام کاربری را وارد کنید.');
    if (amount <= 0 || isNaN(amount)) return showModal('مقدار نامعتبر.');

    const res = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            action:'giveCoin', 
            admin_user: currentUser, 
            targetUser: targetUser, 
            amount: amount
        })
    });
    
    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        await loadData();
        document.getElementById('coinTargetUser').value = '';
        
    }
}

// ================== ارسال پیام خصوصی ==================
async function sendPrivateMessage() {
    const target = document.getElementById('privateTarget').value.trim();
    const message = document.getElementById('privateText').value.trim();

    if (!target || !message) return showModal('نام کاربری و پیام را وارد کنید!');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'addNews',
            message: `پیام از ادمین: ${message}`,
            targetUser: target
        })
    });

    const r = await res.json();
    if (r.success) {
        showModal(`پیام با موفقیت به ${target} ارسال شد.`);
        document.getElementById('privateText').value = '';
        document.getElementById('privateTarget').value = '';
    } else {
        showModal('خطا در ارسال پیام');
    }
}

// ================== ارسال پیام همگانی ==================
async function sendBroadcastMessage() {
    const message = document.getElementById('broadcastText').value.trim();

    if (!message) return showModal('متن پیام را وارد کنید!');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'addNews',
            message: `اطلاعیه از ادمین: ${message}`
        })
    });

    const r = await res.json();
    if (r.success) {
        showModal('پیام با موفقیت برای همه کاربران ارسال شد!');
        document.getElementById('broadcastText').value = '';
    } else {
        showModal('خطا در ارسال پیام');
    }
}

// ارسال گزارش به ادمین
async function submitReport() {
    const subject = document.getElementById('reportSubject').value;
    const message = document.getElementById('reportMessage').value.trim();

    if (!subject) return showModal('لطفاً موضوع گزارش را انتخاب کنید.');
    if (!message) return showModal('لطفاً متن گزارش را بنویسید.');
    if (message.length < 10) return showModal('متن گزارش خیلی کوتاه است.');

    const reportData = {
        action: 'addNews',
        message: `گزارش جدید:\nموضوع: ${subject}\nاز کاربر: ${currentUser}\nمتن: ${message}`,
        targetUser: 'admin'   
    };

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(reportData)
    });

    const r = await res.json();

    if (r.success) {
        document.getElementById('reportSubject').value = '';
        document.getElementById('reportMessage').value = '';
        showModal('گزارش شما با موفقیت ارسال شد!\nلطفاً منتظر باشید تا ادمین ها به شما پاسخ دهند.');
    } else {
        showModal('خطا در ارسال گزارش. دوباره امتحان کنید.');
    }
}

// نمایش گزارشات برای ادمین
function loadAdminReports() {
    if (!isAdmin && !users[currentUser]?.is_helper) return;

    const list = document.getElementById('reportsList');
    const reports = (news || []).filter(n => n.message.includes('گزارش جدید:') || n.message.includes('موضوع: '));

    if (reports.length === 0) {
        list.innerHTML = '<p style="text-align:center; color:#aaa;">هیچ گزارشی ارسال نشده است.</p>';
        return;
    }

    let html = '<table style="width:100%; border-collapse:collapse;">';
    html += '<tr style="background:#333;"><th>کاربر</th><th>زمان</th><th>موضوع</th><th>عملیات</th></tr>';

    reports.reverse().forEach((report, index) => {
        const lines = report.message.split('\n');
        const userLine = lines.find(l => l.includes('از کاربر:'));
        const subjectLine = lines.find(l => l.includes('موضوع:'));
        const textLine = lines.find(l => l.includes('متن:'));

        const username = userLine ? userLine.replace('از کاربر: ', '').trim() : 'نامشخص';
        const subject = subjectLine ? subjectLine.replace('موضوع: ', '').trim() : 'بدون موضوع';
        const time = new Date(report.timestamp).toLocaleString('fa-IR');

        html += `<tr style="border-bottom:1px solid #444;">
            <td>${username}</td>
            <td>${time}</td>
            <td>${subject}</td>
            <td>
        <button class="btn" style="background:#ff9800; padding:8px 15px; font-size:14px; margin:5px;" 
        onclick="showFullReport('${username}', '${subject}', \`${report.message.replace(/`/g, '\\`').replace(/\$/g, '\\$')}\`)">
    مشاهده گزارش کامل
</button>
                <button class="btn" style="background:#4CAF50; padding:8px 15px; font-size:14px; margin:5px;" 
        onclick="markReportAsDone(${report.timestamp})">
    پاسخ داده شد
</button>
            </td>
        </tr>`;
    });

    html += '</table>';
    list.innerHTML = html;
}

function loadAdminSponsors() {
    if (!isAdmin) return;

    const table = document.getElementById('sponsorsAdminList').querySelector('table');
    const noMsg = document.getElementById('sponsorsAdminList').querySelector('p');

    if (!sponsors || sponsors.length === 0) {
        table.style.display = 'none';
        noMsg.style.display = 'block';
        noMsg.textContent = 'هیچ اسپانسری ثبت نشده است.';
        return;
    }

    table.style.display = 'table';
    noMsg.style.display = 'none';

    // مرتب‌سازی جدیدترین اول
    const sortedSponsors = [...sponsors].sort((a, b) => b.timestamp - a.timestamp);

    let rows = '';
    sortedSponsors.forEach(s => {
        const date = new Date(s.timestamp);
        const [jy, jm, jd] = gregorianToJalali(date.getFullYear(), date.getMonth() + 1, date.getDate());
        const hour = date.getHours().toString().padStart(2, '0');
        const minute = date.getMinutes().toString().padStart(2, '0');
        const jalaliDate = `${jy}/${jm.toString().padStart(2,'0')}/${jd.toString().padStart(2,'0')} - ${hour}:${minute}`;

        rows += `<tr style="border-bottom:1px solid #444;">
            <td style="color:#ff9800;">${jalaliDate}</td>
            <td>${(s.views || 0).toLocaleString()}</td>
            <td>${escapeHtml(s.name)}</td>
            <td>
                <button class="btn" style="background:#f44336; padding:8px 15px;" 
                        onclick="deleteSponsor(${s.timestamp})">
                    حذف
                </button>
            </td>
        </tr>`;
    });

    table.innerHTML = `<tr style="background:#333;"><th>زمان انتشار</th><th>تعداد مشاهده</th><th>نام اسپانسر</th><th>عملیات</th></tr>${rows}`;
}

async function deleteSponsor(timestamp) {
    if (!confirm('آیا مطمئن هستید که می‌خواهید این اسپانسر را حذف کنید؟ این عمل قابل بازگشت نیست!')) return;

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'delete_sponsor',
            admin_user: currentUser,
            timestamp: timestamp
        })
    });

    const r = await res.json();
    showModal(r.msg || (r.success ? 'اسپانسر حذف شد.' : 'خطا در حذف.'));

    if (r.success) {
        await loadData(); // بروزرسانی داده‌ها
        loadAdminSponsors(); // بروزرسانی جدول
    }
}

// علامت زدن گزارش به عنوان "پاسخ داده شد" → حذف کامل از سیستم
async function markReportAsDone(timestamp) {
    if (!confirm('آیا این گزارش پاسخ داده شده و باید کاملاً حذف شود؟')) return;

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'delete_report',
            admin_user: currentUser,
            timestamp: timestamp
        })
    });

    const r = await res.json();

    if (r.success) {
        showModal('گزارش با موفقیت حذف شد.');
        await loadData();           // داده‌ها رو دوباره لود کن
        loadAdminReports();         // لیست رو بروزرسانی کن
    } else {
        showModal(r.msg || 'خطا در حذف گزارش.');
    }
}

async function createHelperAccount() {
    if (!isAdmin) return showModal('فقط ادمین می‌تواند حساب هلپری بسازد!');

    const username = document.getElementById('helperUsername').value.trim();
    const pass = document.getElementById('helperPass').value;

    if (!username || !pass) return showModal('نام کاربری و رمز را وارد کنید');
    if (username.length < 3) return showModal('نام کاربری حداقل ۳ کاراکتر');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({
            action: 'create_helper',
            admin_user: currentUser,
            username: username,
            pass: pass
        })
    });

    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        document.getElementById('helperUsername').value = '';
        document.getElementById('helperPass').value = '';
    }
}

// ادمین: حذف کامل حساب کاربری
async function deleteUser() {
    if (!currentUser || !isAdmin) return showModal('شما اجازه انجام این عملیات را ندارید.');

    const targetUser = document.getElementById('deleteTargetUser').value.trim();
    if (targetUser.length === 0) {
        return showModal('لطفاً نام کاربری را وارد کنید.');
    }
    if (targetUser.toLowerCase() === 'admin') {
         return showModal('⚠️ شما نمی‌توانید حساب ادمین اصلی را حذف کنید.');
    }

    // تأیید دو مرحله‌ای
    const confirmation = confirm(`آیا از حذف کامل حساب [${targetUser}] مطمئنی؟ این کار قابل بازگشت نیست!`);
    if (!confirmation) return;
    
    // ارسال درخواست POST
    const res = await fetch('', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({
            action:'delete_user', 
            admin_user: currentUser, // ارسال نام ادمین برای چک امنیتی سمت سرور
            targetUser: targetUser
        })
    });
    
    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        // پس از حذف موفق، لیست کاربران را به‌روزرسانی کنید و فیلد را خالی کنید
        await loadData();
        document.getElementById('deleteTargetUser').value = '';
        loadAdminUserList(); // به‌روزرسانی جدول کاربران
    }
}


async function loadAdminUserCount() {
    if (!currentUser || !isAdmin) return;
    
    await loadData(); // برای اطمینان از جدیدترین داده‌ها
    const userCount = Object.keys(users).length;
    document.getElementById('totalUserCount').textContent = userCount.toLocaleString();
}


function loadAdminUserList() {
    if (!currentUser || !isAdmin) return;
    
    const table = document.getElementById('allUsersTable');
    const search = document.getElementById('userSearch').value.toLowerCase();
    
    let html = `<tr><th>نام کاربری</th><th>رمز</th><th>وضعیت</th><th>موجودی</th></tr>`;

    const allUsers = Object.entries(users)
        .filter(([name, data]) => name.toLowerCase().includes(search));

    allUsers.forEach(([name, data]) => {
        const status = data.is_banned ? 'مسدود' : (data.is_admin ? 'ادمین' : 'فعال');
        const rowClass = data.is_banned ? 'style="background:#f4433655;"' : (data.is_admin ? 'style="background:#4CAF5055;"' : '');
        
        html += `<tr ${rowClass}>
            <td>${name}</td>
            <td>${data.pass}</td>
            <td>${status}</td>
            <td>${(data.balance || 0).toLocaleString()}</td>
        </tr>`;
    });
    
    table.innerHTML = html;
}

// لیست کاربران بن شده
function loadBannedUsers() {
    if (!isAdmin) return;

    const table = document.getElementById('bannedUsersTable');
    const countEl = document.getElementById('bannedCount');
    let html = '';
    let count = 0;

    for (const [username, data] of Object.entries(users)) {
        if (data.is_banned) {
            count++;
            const banDate = data.ban_date ? new Date(data.ban_date).toLocaleDateString('fa-IR') : 'نامشخص';
            html += `
                <tr style="background:#f4433655;">
                    <td>${username}</td>
                    <td>${(data.balance || 0).toLocaleString()}</td>
                    <td>${banDate}</td>
                    <td>
                        <button class="btn" style="background:#4CAF50; padding:8px 15px; font-size:14px;" 
                                onclick="unbanUser('${username}')">باز کردن بن</button>
                    </td>
                </tr>`;
        }
    }

    table.innerHTML = html || '<tr><td colspan="4" style="text-align:center; color:#aaa;">هیچ کاربری بن نشده است.</td></tr>';
    countEl.textContent = count;
}

// جستجو در لیست بن‌شده‌ها
function filterBannedUsers() {
    const search = document.getElementById('bannedSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#bannedUsersTable tr');

    rows.forEach(row => {
        const username = row.cells[0]?.textContent.toLowerCase() || '';
        row.style.display = username.includes(search) ? '' : 'none';
    });
}

// باز کردن بن کاربر (دکمه سبز)
async function unbanUser(username) {
    if (confirm(`آیا از باز کردن بن کاربر ${username} مطمئن هستید؟`)) {
        await fetch('', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({
                action: 'toggleBan',
                admin_user: currentUser,
                targetUser: username,
                shouldBan: false
            })
        });
        await loadData();
        loadBannedUsers(); // دوباره لیست رو بارگذاری کن
        showModal(`بن کاربر ${username} با موفقیت برداشته شد.`);
    }
}

function filterUsers() {
    loadAdminUserList(); // تابع بارگذاری را با فیلتر جدید اجرا می‌کند
}

// ---------------------------------------------------------------------------------
// توابع لیدربورد
// ---------------------------------------------------------------------------------
function loadLeaderboard() {
    const table = document.getElementById('topPlayers');
    const userList = Object.entries(users)
        .filter(([name, data]) => !data.is_admin && !data.is_banned) // ادمین و مسدود شده‌ها حذف می‌شوند
        .map(([name, data]) => ({
            username: name,
            totalValue: Math.floor((data.balance || 0) + Object.entries(data.crypto).reduce((acc, [coin, bal]) => acc + (bal * (prices[coin] || 0)), 0))
        }))
        .sort((a, b) => b.totalValue - a.totalValue)
        .slice(0, 50); // ۵۰ نفر برتر

    let html = `<tr><th>رتبه</th><th>نام کاربری</th><th>ارزش کل دارایی</th></tr>`;

    userList.forEach((user, index) => {
        html += `<tr>
            <td>${index + 1}</td>
            <td>${user.username}</td>
            <td>${user.totalValue.toLocaleString()}</td>
        </tr>`;
    });
    
    table.innerHTML = html;
}

// ---------------------------------------------------------------------------------
// اجرای اولیه
// ---------------------------------------------------------------------------------

// تابع ثبت اسپانسر توسط ادمین
async function addSponsor() {
    if (!isAdmin) return showModal('فقط ادمین می‌تواند اسپانسر ثبت کند.');

    const name = document.getElementById('sponsorName').value.trim();
    const desc = document.getElementById('sponsorDesc').value.trim();
    const link = document.getElementById('sponsorLink').value.trim();

    if (!name || !link) return showModal('نام و لینک الزامی است.');

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'add_sponsor',
            admin_user: currentUser,
            sponsor_name: name,
            sponsor_desc: desc,
            sponsor_link: link
        })
    });

    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        document.getElementById('sponsorName').value = '';
        document.getElementById('sponsorDesc').value = '';
        document.getElementById('sponsorLink').value = '';
        await loadData(); // بروزرسانی داده‌ها
    }
}

// تابع کمکی تبدیل تاریخ میلادی به شمسی (جلالی) — ساده و بدون نیاز به لایبرری
function gregorianToJalali(gy, gm, gd) {
    let g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    let jy = (gy <= 1600) ? 0 : 979;
    gy -= (gy <= 1600) ? 621 : 1600;
    let gy2 = (gm > 2) ? (gy + 1) : gy;
    let days = (365 * gy) + Math.floor((gy2 + 3) / 4) - Math.floor((gy2 + 99) / 100) + Math.floor((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
    jy += 33 * Math.floor(days / 12053);
    days %= 12053;
    jy += 4 * Math.floor(days / 1461);
    days %= 1461;
    if (days > 365) {
        jy += Math.floor((days - 1) / 365);
        days = (days - 1) % 365;
    }
    let jm = (days < 186) ? 1 + Math.floor(days / 31) : 7 + Math.floor((days - 186) / 30);
    let jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
    return [jy, jm, jd];
}

// تابع نمایش لیست اسپانسرها — حالا با فیلد "تاریخ ثبت" به شمسی اضافه شده
function loadSponsors() {
    const list = document.getElementById('sponsorsList');
    
    if (!sponsors || sponsors.length === 0) {
        list.innerHTML = '<div style="text-align:center; padding:50px; background:#ffffff11; border-radius:20px; width:100%; max-width:600px;"><p style="font-size:24px; color:#ff9800;">هنوز اسپانسری ثبت نشده است.</p></div>';
        return;
    }

    // مرتب‌سازی بر اساس جدیدترین
    const sortedSponsors = [...sponsors].sort((a, b) => b.timestamp - a.timestamp);

    let html = '';
    sortedSponsors.forEach(s => {
        // تبدیل timestamp به تاریخ شمسی + ساعت و دقیقه
        const date = new Date(s.timestamp);
        const [jy, jm, jd] = gregorianToJalali(date.getFullYear(), date.getMonth() + 1, date.getDate());
        const hour = date.getHours().toString().padStart(2, '0');
        const minute = date.getMinutes().toString().padStart(2, '0');
        const jalaliDate = `${jy}/${jm.toString().padStart(2,'0')}/${jd.toString().padStart(2,'0')} - ${hour}:${minute}`;

        html += `
        <div class="product-card my-miner-card" style="text-align:right; direction:rtl; width:100%; max-width:600px; height:auto;">
            <h2 style="color:#ffc800; text-align:center;">${escapeHtml(s.name)}</h2>
            
            <div class="product-info-row">
                <strong>تاریخ ثبت:</strong>
                <span class="value" style="color:#ff9800; font-weight:bold;">${jalaliDate}</span>
            </div>

            ${s.description ? `
            <div class="product-info-row">
                <strong>توضیحات:</strong>
                <span class="value" style="color:#aaa; white-space:pre-wrap;">${escapeHtml(s.description)}</span>
            </div>` : ''}
            
            <div class="miner-buttons" style="margin-top:auto;">
                <button class="btn buy-btn" style="background:#4CAF50; width:100%; padding:15px; font-size:18px;" 
                        onclick="openSponsorLink('${escapeHtml(s.link)}')">
                    بازدید از اسپانسر 🚀
                </button>
            </div>
        </div>`;
    });

    list.innerHTML = html;
}

// تابع کمکی برای جلوگیری از XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.onload = async () => {
    await loadData();
    
    if (currentUser && users[currentUser]) {
        isAdmin = users[currentUser].is_admin || false;
        setupUser();

        // تعیین داشبورد اصلی بر اساس نقش کاربر
        const mainDashboard = getMainDashboard();

        // در صورتی که کاربر قبلاً لاگین کرده باشد، بخش مربوطه را نمایش می‌دهیم.
        if (currentUser) {
            document.getElementById('welcome').classList.remove('active');
            document.getElementById(mainDashboard).classList.add('active');
            loadUserData();
        }
    } else {
        // کاربر لاگین نکرده است → welcome رو فعال کن
        document.getElementById('welcome').classList.add('active');
        document.getElementById('newsBell').style.display = 'none'; // زنگوله را پنهان کن
    }

    // <<< جدید: همیشه در انتها spinner رو مخفی کن
    const loadingOverlay = document.getElementById('loadingOverlay');
    if (loadingOverlay) {
        loadingOverlay.classList.add('hidden');
    }

    // بقیه کارهای اولیه (چک خبرهای unread و ...)
    checkUnreadNews();
    updateBackButtonVisibility();
};

// نمایش تایمر بن ماین
// نمایش تایمر بن ماین
// نمایش تایمر بن ماین — کاملاً اصلاح‌شده
function updateMineBanDisplay() {
    if (!currentUser || !users[currentUser]) return;

    const mineSection = document.getElementById('mine');
    if (!mineSection) return;

    let timer = document.getElementById('mineBanTimer');
    if (!timer) {
        timer = document.createElement('div');
        timer.id = 'mineBanTimer';
        timer.style.cssText = 'background:#d32f2f; color:white; padding:15px; border-radius:15px; margin:20px; font-size:18px; font-weight:bold; text-align:center;';
        mineSection.insertBefore(timer, mineSection.firstChild);
    }

    // فقط از دیتای کاربر فعلی استفاده می‌کنیم — هیچ متغیر گلوبالی!
    const banEnd = users[currentUser].mine_ban_end || 0;
    const now = Date.now();
    const timeLeft = banEnd - now;

    const bigBtn = document.querySelector('.btn-big');

    if (timeLeft <= 0 || banEnd === 0) {
        timer.style.display = 'none';
        if (bigBtn) bigBtn.disabled = false;
        if (mineBanInterval) {
            clearInterval(mineBanInterval);
            mineBanInterval = null;
        }
    } else {
        timer.style.display = 'block';
        const hours = Math.floor(timeLeft / 3600000);
        const minutes = Math.floor((timeLeft % 3600000) / 60000);
        const seconds = Math.floor((timeLeft % 60000) / 1000);
        timer.innerHTML = `محدودیت ماین به دلیل اتوکلیکر<br>${hours} ساعت و ${minutes} دقیقه و ${seconds} ثانیه باقی مانده`;

        if (bigBtn) bigBtn.disabled = true;

        // فقط یکبار interval بساز
        if (!mineBanInterval) {
            mineBanInterval = setInterval(updateMineBanDisplay, 1000);
        }
    }
}

// وقتی وارد بخش ماین می‌شی، چک کن بن داری یا نه
const oldLoadMine = loadMine;
loadMine = function() {
    oldLoadMine(); // اول کارهای قبلی انجام بشه

    // فقط چک کن، هیچ متغیر گلوبالی ست نکن!
    if (users[currentUser]?.mine_ban_end > Date.now()) {
        updateMineBanDisplay(); // خودش مقدار درست رو می‌خونه
    } else {
        // اگر بن نداره، مطمئن شو تایمر پاک بشه
        const timer = document.getElementById('mineBanTimer');
        if (timer) timer.style.display = 'none';
        const bigBtn = document.querySelector('.btn-big');
        if (bigBtn) bigBtn.disabled = false;
        if (mineBanInterval) {
            clearInterval(mineBanInterval);
            mineBanInterval = null;
        }
    }
};

// index1.php (در بخش <script>)

// ... سایر توابع جاوااسکریپت

function openChat() {
    // استفاده از متغیر سراسری currentUser که حاوی نام کاربری لاگین شده است.
    if (currentUser && users[currentUser]) {
        // نام کاربری (رشته) را به عنوان پارامتر 'username' ارسال می‌کنیم.
        window.location.href = 'chat.php?username=' + currentUser; 
    } else {
        showModal('ابتدا باید وارد سایت شوید.');
    }
}

// ==================== سیستم بازگشت هوشمند (نسخه نهایی - کامل با دکمه سیستم موبایل/مرورگر) ====================
let navigationHistory = [];
let isGoingBack = false;

window.addEventListener('popstate', (event) => {
    goBack();
});

function showSection(sectionId) {
    // چک دسترسی (دقیقاً همون کدهای قبلی‌ت — بدون تغییر)
    if (sectionId === 'report' && users[currentUser]?.is_helper) {
        showModal('هلپر نیازی به ارسال گزارش ندارد!');
        return;
    }

    const adminOnlySections = [
        'adminDashboard', 'createHelper', 'adminUsers', 'adminToggleBan', 'adminGiveCoin',
        'adminUserCount', 'adminDeleteUser', 'adminBannedUsers', 'adminReports',
        'sendMessage', 'privateMessage', 'broadcastMessage', 'fullReport',
        'helperDashboard', 'adminSponsors', 'addSponsor'
    ];

    const isAdminOrHelper = isAdmin || (currentUser && users[currentUser]?.is_helper);

    if (isAdminOrHelper && !adminOnlySections.includes(sectionId) && !['welcome', 'sponsors'].includes(sectionId)) {
        showModal('دسترسی ممنوع — شما اجازه ورود به این بخش را ندارید.');
        return;
    }

    if (!isAdminOrHelper && adminOnlySections.includes(sectionId)) {
        showModal('دسترسی ممنوع — این بخش فقط برای ادمین و هلپر است.');
        return;
    }

    const currentActive = document.querySelector('.section.active');
    const currentId = currentActive ? currentActive.id : null;

    let shouldPushHistory = false;

    if (currentId && currentId !== sectionId && !isGoingBack) {
        const mainDashboard = getMainDashboard();
        if (sectionId !== mainDashboard && sectionId !== 'welcome') {
            navigationHistory.push(currentId);
            shouldPushHistory = true;
        } else {
            navigationHistory = [];
        }
    }

    // توقف polling اسپانسرها
    if (currentId === 'sponsors' && sponsorsRefreshInterval) {
        clearInterval(sponsorsRefreshInterval);
        sponsorsRefreshInterval = null;
    }

    // نمایش بخش جدید
    document.querySelectorAll('.section').forEach(s => s.classList.remove('active'));
    document.getElementById(sectionId).classList.add('active');

    // اجرای توابع خاص هر بخش (همون قبلی‌ها)
    if (sectionId === 'mine') loadMine();
    if (sectionId === 'exchange') startPriceUpdateChecker();
    else stopPriceUpdateChecker();
    if (sectionId === 'barracks') updateBarracksDisplay();
    if (sectionId === 'attack') updateAttackDisplay();
    if (sectionId === 'leaderboard') loadLeaderboard();
    if (sectionId === 'news') loadNews();
    if (sectionId === 'shop') updateShopStats();
    if (sectionId === 'myproducts') {
        loadMyProducts();
        startMinerTimers();
    }
    if (sectionId === 'adminUsers') loadAdminUserList();
    if (sectionId === 'adminBannedUsers') loadBannedUsers();
    if (sectionId === 'adminReports') loadAdminReports();
    if (sectionId === 'adminUserCount') loadAdminUserCount();
    if (sectionId === 'sponsors') {
        loadSponsors();
        if (!isAdmin && currentUser) {
            fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'increment_sponsor_views',
                    username: currentUser
                })
            });
        }
        if (sponsorsRefreshInterval) clearInterval(sponsorsRefreshInterval);
        sponsorsRefreshInterval = setInterval(async () => {
            await loadData();
            loadSponsors();
        }, 10000);
    }
    if (sectionId === 'adminSponsors') loadAdminSponsors();

    // <<< مهم: push به history مرورگر برای فعال کردن دکمه سیستم
    if (shouldPushHistory) {
        history.pushState({ section: sectionId }, document.title, location.href);
    }

    loadUserData();
    checkUnreadNews();
    updateBackButtonVisibility();
}

function goBack() {
    if (navigationHistory.length === 0) {
        showSection(getMainDashboard());
        return;
    }

    isGoingBack = true;
    const previousSection = navigationHistory.pop();
    showSection(previousSection);
    isGoingBack = false;

    updateBackButtonVisibility();
}

function updateBackButtonVisibility() {
    const backBtn = document.getElementById('backBtn');
    if (!backBtn) return;

    const activeSection = document.querySelector('.section.active');
    if (!activeSection) {
        backBtn.style.display = 'none';
        return;
    }

    const currentSection = activeSection.id;
    const mainDashboard = getMainDashboard();

    if (currentSection === mainDashboard || currentSection === 'welcome') {
        backBtn.style.display = 'none';
        navigationHistory = [];
    } else if (navigationHistory.length > 0) {
        backBtn.style.display = 'inline-block';
    } else {
        backBtn.style.display = 'none';
    }
}

// ریست تاریخچه موقع لاگین
const originalSetupUser = setupUser;
setupUser = function() {
    if (originalSetupUser) originalSetupUser();
    navigationHistory = [];
    updateBackButtonVisibility();
};

// آکاردئون راهنما
document.querySelectorAll('.guide-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const panel = this.nextElementSibling;
                    const isActive = this.classList.contains('active');

                    // بستن همه
                    document.querySelectorAll('.guide-panel').forEach(p => p.style.display = 'none');
                    document.querySelectorAll('.guide-btn').forEach(b => b.classList.remove('active'));

                    // باز کردن مورد کلیک شده
                    if (!isActive) {
                        this.classList.add('active');
                        panel.style.display = 'block';
                    }
                });
            });

            // اولین مورد همیشه باز باشه
            document.querySelector('.guide-btn').click();

// نمایش گزارش کامل — نسخه درست و کامل (تمام متن میاد)
function showFullReport(username, subject, fullMessage) {
    // جدا کردن بخش "متن گزارش:" به بعد (برای زیبایی بیشتر)
    const messageStart = fullMessage.indexOf('متن:');
    let cleanMessage = 'متن گزارش موجود نیست.';
    
    if (messageStart !== -1) {
        cleanMessage = fullMessage.substring(messageStart + 4).trim(); // بعد از "متن:"
    }

    const content = `گزارش از: ${username}
موضوع: ${subject}

متن کامل گزارش:
${cleanMessage}`;

    document.getElementById('fullReportContent').textContent = content;
    showSection('fullReport');
}

// متغیر جهانی برای interval تایمرها
let minerTimersInterval = null;
let needsReload = false;

function updateAllMinerTimers() {
    if (!currentUser || !users[currentUser] || !users[currentUser].miners) return;

    needsReload = false;
    const now = Date.now();
    const cycleDuration = 60000; // باید با سرور یکی باشد (۱ دقیقه)

    Object.keys(users[currentUser].miners).forEach(minerId => {
        const miner = users[currentUser].miners[minerId];
        const lastTime = miner.last_collect_time || 0;
        const nextTime = miner.next_collect_time || 0;
        let timeLeft = nextTime - now;

        // تایمر
        const timerEl = document.getElementById(`nextCollectTimer-${minerId}`);
        if (timerEl) {
            if (timeLeft <= 0) {
                needsReload = true;
                timerEl.textContent = "00:00";
                timerEl.style.color = "#4CAF50";
            } else {
                let displayText = "";
                if (timeLeft > 3600000) {
                    const hours = Math.floor(timeLeft / 3600000).toString().padStart(2, '0');
                    const minutes = Math.floor((timeLeft % 3600000) / 60000).toString().padStart(2, '0');
                    const seconds = Math.floor((timeLeft % 60000) / 1000).toString().padStart(2, '0');
                    displayText = `${hours}:${minutes}:${seconds}`;
                } else {
                    const minutes = Math.floor(timeLeft / 60000).toString().padStart(2, '0');
                    const seconds = Math.floor((timeLeft % 60000) / 1000).toString().padStart(2, '0');
                    displayText = `${minutes}:${seconds}`;
                }
                timerEl.textContent = displayText;
                timerEl.style.color = "#ff9800";
            }
        }

        // <<< جدید: آپدیت زنده مبلغ قابل برداشت (دقیقاً همان چیزی که سرور برداشت می‌کند)
        const collectableEl = document.querySelector(`.my-miner-card[data-miner-id="${minerId}"] .collectable`);
        if (collectableEl) {
            // محاسبه چرخه‌های کامل گذشته از آخرین جمع‌آوری
            const cyclesPassed = Math.floor((now - lastTime) / cycleDuration);
            let displayCollectable = (miner.collectable || 0) + cyclesPassed * (miner.rate || 10000);

            // اگر ظرفیت پر شود، بیشتر از ظرفیت نشان نده
            if (displayCollectable > miner.capacity) {
                displayCollectable = miner.capacity;
            }

            // جدا کردن واحد (داناکوین / بیت‌کوین / لایت‌کوین)
            let unit = 'داناکوین';
            if (miner.type === 'bitcoin') unit = 'بیت‌کوین';
            if (miner.type === 'litecoin') unit = 'لایت‌کوین';

            collectableEl.textContent = displayCollectable.toLocaleString() + ' ' + unit;
            // اگر مقدار > 0 باشد رنگ سبز کن
            collectableEl.style.color = displayCollectable > 0 ? '#4CAF50' : '#fff';
        }
    });

    if (needsReload) {
        loadData().then(() => {
            loadMyProducts();
            updateAllMinerTimers();
        });
    }
}

// شروع آپدیت تایمرها وقتی وارد بخش محصولات من می‌شیم
function startMinerTimers() {
    updateAllMinerTimers(); // اولین آپدیت فوری
    if (minerTimersInterval) clearInterval(minerTimersInterval);
    minerTimersInterval = setInterval(updateAllMinerTimers, 1000); // هر ثانیه آپدیت
}

// توقف تایمرها وقتی از بخش خارج می‌شیم (بهینه‌سازی)
function stopMinerTimers() {
    if (minerTimersInterval) {
        clearInterval(minerTimersInterval);
        minerTimersInterval = null;
    }
}

function searchMiners() {
    const input = document.getElementById('minerSearchInput');
    const filter = input.value.trim().toLowerCase();
    const cards = document.querySelectorAll('#myMinersList .my-miner-card');

    cards.forEach(card => {
        const nameEl = card.querySelector('.custom-name');
        const name = nameEl ? nameEl.textContent.toLowerCase() : '';

        if (filter === '' || name.includes(filter)) {
            card.style.display = 'block';
        } else {
            card.style.display = 'none';
        }
    });

    // اگر هیچ ماشینی پیدا نشد، پیغام نمایش بده
    const visibleCards = Array.from(cards).filter(card => card.style.display !== 'none');
    if (visibleCards.length === 0 && filter !== '') {
        if (document.getElementById('noMinerFound')) return;
        const msg = document.createElement('div');
        msg.id = 'noMinerFound';
        msg.innerHTML = `
            <div style="text-align:center; padding:50px; background:#ffffff11; border-radius:20px; margin:40px auto; max-width:600px;">
                <p style="font-size:24px; color:#ff9800;">ماشینی با این نام یافت نشد!</p>
                <p style="font-size:18px; color:#aaa;">نام دقیق ماشین خود را وارد کنید</p>
            </div>`;
        document.getElementById('myMinersList').appendChild(msg);
    } else {
        const noMsg = document.getElementById('noMinerFound');
        if (noMsg) noMsg.remove();
    }
}

async function buyBitcoinMiner() {
    if (isAdmin) return showModal('ادمین نمی‌تواند خرید کند.');

    await loadData(); // مطمئن بشیم موجودی به‌روزه
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است.');

    const price = 200000;

    if ((users[currentUser].balance || 0) < price) {
        return showModal('داناکوین کافی نیست! نیاز به ۲۰۰,۰۰۰ داناکوین برای خرید ماشین استخراج بیت‌کوین دارید.');
    }

    // مرحله تأیید خرید (دقیقاً مثل ماشین عادی)
    const confirmation = confirm(
        `آیا مطمئن هستید که می‌خواهید ماشین استخراج بیت‌کوین را با قیمت ${price.toLocaleString()} داناکوین خریداری کنید؟`
    );

    if (!confirmation) {
        return showModal('خرید لغو شد.');
    }

    // اگر کاربر تأیید کرد، درخواست رو بفرست
    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'buy_bitcoin_miner',
            username: currentUser
        })
    });

    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        await loadData();
        loadUserData();
        updateShopStats(); // بروزرسانی آمار فروشگاه (تعداد ماشین‌های بیت‌کوین و ...)
        // اگر می‌خوای بعد از خرید مستقیم بره به بخش محصولات من، این خط رو فعال کن:
        // showSection('myproducts');
    }
}

async function buyLitecoinMiner() {
    if (isAdmin) return showModal('ادمین نمی‌تواند خرید کند.');

    await loadData();
    if (users[currentUser].is_banned) return showModal('حساب شما مسدود است.');

    const price = 2000;

    if ((users[currentUser].balance || 0) < price) {
        return showModal('داناکوین کافی نیست! نیاز به ۲,۰۰۰ داناکوین برای خرید ماشین استخراج لایت‌کوین دارید.');
    }

    const confirmation = confirm(
        `آیا مطمئن هستید که می‌خواهید ماشین استخراج لایت‌کوین را با قیمت ${price.toLocaleString()} داناکوین خریداری کنید؟`
    );

    if (!confirmation) {
        return showModal('خرید لغو شد.');
    }

    const res = await fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            action: 'buy_litecoin_miner',
            username: currentUser
        })
    });

    const r = await res.json();
    showModal(r.msg);

    if (r.success) {
        await loadData();
        loadUserData();
        updateShopStats();
    }
}

function getMainDashboard() {
    if (!currentUser || !users[currentUser]) return 'welcome';
    
    if (users[currentUser].is_banned) return 'welcome';
    if (users[currentUser].is_admin) return 'adminDashboard';
    if (users[currentUser].is_helper) return 'helperDashboard';
    return 'dashboard';
}

function togglePassword() {
    const passInput = document.getElementById("regPass");
    const eyeIcon = document.getElementById("toggleEye");
    
    if (passInput.type === "password") {
        passInput.type = "text";
        eyeIcon.textContent = "🙈";  // چشم بسته = مخفی
    } else {
        passInput.type = "password";
        eyeIcon.textContent = "👁️";  // چشم باز = نمایش
    }
}

let sponsorLoadingTimeout = null;

let sponsorErrorTimeout = null;
let sponsorAutoCloseTimeout = null;

function openSponsorLink(url) {
    const overlay = document.getElementById('sponsorLoadingOverlay');
    const content = document.getElementById('sponsorLoadingContent');
    const closeBtn = document.getElementById('sponsorCloseBtn');

    // اگر overlay قبلاً باز باشه، نذار دوباره باز بشه
    if (overlay.style.display === 'flex') {
        return;
    }

    // ریست کردن محتوا به حالت اولیه
    content.innerHTML = `
        <div style="background: #ff9800; padding: 25px 50px; border-radius: 25px; box-shadow: 0 0 30px rgba(255, 152, 0, 0.9);">
            <p style="margin: 0; font-weight: bold; font-size: 28px;">کمی صبر کنید ...</p>
            <p style="margin: 15px 0 0; font-size: 20px;">در حال انتقال به سایت اسپانسر</p>
        </div>
        <p style="margin-top: 40px; font-size: 18px; opacity: 0.9;">پس از بازدید می‌توانید به بازی برگردید</p>
    `;
    closeBtn.style.display = 'none';

    // نمایش overlay
    overlay.style.display = 'flex';

    // باز کردن لینک در تب جدید
    window.open(url, '_blank');

    // پاک کردن تایمرهای قبلی
    if (sponsorErrorTimeout) clearTimeout(sponsorErrorTimeout);
    if (sponsorAutoCloseTimeout) clearTimeout(sponsorAutoCloseTimeout);

    // بعد از ۱۰ ثانیه: نمایش پیام خطا + دکمه بستن
    sponsorErrorTimeout = setTimeout(() => {
        content.innerHTML = `
            <div style="background: #f44336; padding: 25px 50px; border-radius: 25px; box-shadow: 0 0 30px rgba(244, 67, 54, 0.9);">
                <p style="margin: 0; font-weight: bold; font-size: 26px;">صفحه مورد نظر درست لینک نشده است یا فیلترشکن شما وصل نیست!</p>
            </div>
            <p style="margin-top: 30px; font-size: 18px; opacity: 0.9;">لطفاً فیلترشکن خود را چک کنید یا بعداً دوباره امتحان کنید.</p>
        `;
        closeBtn.style.display = 'block';
    }, 10000); // ۱۰ ثانیه

    // بعد از ۳۰ ثانیه: مخفی کردن خودکار overlay
    sponsorAutoCloseTimeout = setTimeout(() => {
        closeSponsorOverlay();
    }, 30000); // ۳۰ ثانیه
}

function closeSponsorOverlay() {
    const overlay = document.getElementById('sponsorLoadingOverlay');
    overlay.style.display = 'none';

    if (sponsorErrorTimeout) {
        clearTimeout(sponsorErrorTimeout);
        sponsorErrorTimeout = null;
    }
    if (sponsorAutoCloseTimeout) {
        clearTimeout(sponsorAutoCloseTimeout);
        sponsorAutoCloseTimeout = null;
    }
}

// کلیک روی پس‌زمینه overlay هم ببنده
document.getElementById('sponsorLoadingOverlay').addEventListener('click', function(e) {
    if (e.target === this) {
        closeSponsorOverlay();
    }
});

</script>

<!-- بخش نمایش کامل گزارش - فقط برای ادمین -->
<div id="fullReport" class="section">
    <h1>مشاهده گزارش کامل</h1>
    <div style="background:#ffffff11; padding:30px; border-radius:20px; margin:20px auto; max-width:800px; text-align:right; direction:rtl; line-height:2;">
        <div style="background:#1e1e2e; padding:25px; border-radius:15px; border:2px solid #ff9800; min-height:300px; font-size:18px; white-space:pre-wrap;">
            <div id="fullReportContent">در حال بارگذاری...</div>
        </div>
        <br>
    </div>
</div>

<!-- بخش فروشگاه -->
<div id="shop" class="section">
    <h1>فروشگاه</h1>
    <!-- کارت محصول: ماشین استخراج داناکوین -->
    <div class="product-card">
    <h2>ماشین استخراج داناکوین</h2>
    <div class="product-info-row">
        <strong>تعداد موجود در بین کاربران:</strong>
        <span class="value"><span id="totalMinersBought">0</span></span>
    </div>
    <div class="product-info-row">
        <strong>قیمت:</strong>
        <span class="value">250,000 داناکوین</span>
    </div>
    <div class="product-info-row">
        <strong>توضیحات:</strong>
        <span class="value">استخراج خودکار داناکوین حتی در حالت آفلاین</span>
    </div>

    <div class="product-info-row">
        <strong>تعداد خریداری شده توسط شما:</strong>
        <span class="value"><span id="ownedNormalMiners">0</span>/4</span>
    </div>

    <div class="miner-buttons">
        <button class="btn buy-btn" onclick="buyMiner()">خرید ماشین</button>
    </div>
</div>
<div class="product-card">
        <h2>ماشین استخراج بیت‌کوین</h2>
        <div class="product-info-row">
            <strong>تعداد موجود در بین کاربران:</strong>
            <span class="value"><span id="totalBitcoinMinersBought">0</span></span>
        </div>
        <div class="product-info-row">
            <strong>قیمت:</strong>
            <span class="value">500,000 داناکوین</span>
        </div>
        <div class="product-info-row">
            <strong>توضیحات:</strong>
            <span class="value">استخراج خودکار بیت‌کوین حتی در حالت آفلاین</span>
        </div>

        <div class="product-info-row">
        <strong>تعداد خریداری شده توسط شما:</strong>
        <span class="value"><span id="ownedBitcoinMiners">0</span>/4</span>
    </div>

        <div class="miner-buttons">
            <button class="btn buy-btn" onclick="buyBitcoinMiner()">خرید ماشین بیت‌کوین</button>
        </div>
    </div>

    <div class="product-card">
        <h2>ماشین استخراج لایت‌کوین</h2>
        <div class="product-info-row">
            <strong>تعداد موجود در بین کاربران:</strong>
            <span class="value"><span id="totalLitecoinMinersBought">0</span></span>
        </div>
        <div class="product-info-row">
            <strong>قیمت:</strong>
            <span class="value">2,000 داناکوین</span>
        </div>
        <div class="product-info-row">
            <strong>توضیحات:</strong>
            <span class="value">استخراج خودکار لایت‌کوین حتی در حالت آفلاین</span>
        </div>

        <div class="product-info-row">
        <strong>تعداد خریداری شده توسط شما:</strong>
        <span class="value"><span id="ownedLitecoinMiners">0</span>/4</span>
    </div>

        <div class="miner-buttons">
            <button class="btn buy-btn" onclick="buyLitecoinMiner()">خرید ماشین لایت‌کوین</button>
        </div>
    </div></div>

<!-- بخش محصولات من -->
<div id="myproducts" class="section">
    <h1>محصولات من</h1>

    <div style="margin: 20px auto; max-width: 500px; text-align: center;">
        <input type="text" id="minerSearchInput" placeholder="جستجوی نام ماشین..." 
               style="width: 100%; padding: 15px; border-radius: 15px; border: none; background: #ffffff22; color: #fff; font-size: 18px; text-align: center;"
               onkeyup="searchMiners()">
        <p style="margin-top: 10px; color: #aaa; font-size: 14px;">نام شخصی‌سازی شده ماشین خود را وارد کنید</p>
    </div>

    <div id="myMinersList">
        <div style="text-align:center; padding:50px; background:#ffffff11; border-radius:20px; margin:40px auto; max-width:600px;">
            <p style="font-size:24px; color:#ff9800;">شما هنوز هیچ محصولی خریداری نکرده‌اید.</p>
            <p style="font-size:18px; color:#aaa; margin-top:20px;">به فروشگاه سر بزنید و اولین ماشین استخراج خود را بخرید!</p>
        </div>
    </div>
</div>

<!-- قالب مخفی برای کارت ماشین استخراج در محصولات من -->
<div id="minerTemplate" style="display:none;">
    <div class="product-card my-miner-card" data-miner-id="TEMPLATE_ID">
        <h2>ماشین استخراج <span class="unit-type">داناکوین</span></h2> <!-- جدید: unit-type برای تغییر عنوان اصلی -->
        
        <div class="product-info-row">
            <strong>نام ماشین:</strong>
            <span class="value custom-name">اسمی انتخاب نشده است</span>
        </div>
        
        <div class="product-info-row">
            <strong>دریافت در دقیقه (سطح <span class="rate-level">1</span>):</strong>
            <span class="value"><span class="rate">1,000</span> <span class="unit">داناکوین</span></span> <!-- جدید: unit برای تغییر واحد -->
        </div>

        <div class="product-info-row">
            <strong>هزینه ارتقا دریافت:</strong>
            <span class="value next-rate-cost">در حال محاسبه...</span>
        </div>

        <div class="product-info-row">
            <strong>ظرفیت مخزن (سطح <span class="capacity-level">1</span>):</strong>
            <span class="value"><span class="capacity">5,000</span> <span class="unit">داناکوین</span></span> <!-- جدید: unit برای تغییر واحد -->
        </div>
        
        <div class="product-info-row">
            <strong>هزینه ارتقا ظرفیت:</strong>
            <span class="value next-capacity-cost">در حال محاسبه...</span>
        </div>
        
        <div class="product-info-row">
            <strong>مبلغ قابل برداشت:</strong>
            <span class="value collectable">0 <span class="unit">داناکوین</span></span> <!-- جدید: unit برای تغییر واحد -->
        </div>

        <div class="product-info-row">
            <strong>زمان تا دریافت بعدی:</strong>
            <span class="value" id="nextCollectTimer-TEMPLATE_ID" style="color:#ff9800; font-weight:bold;">01:00:00</span>
        </div>
        
        <div class="product-info-row">
            <strong>قیمت فروش به سایت:</strong>
            <span class="value sell-price"> 125,000</span>
        </div>
        
        <div class="product-info-row">
            <strong>وضعیت محصول:</strong>
            <span class="value status" style="color:red;">کامل نشده</span>
        </div>
        
        <div class="miner-buttons">
            <button class="btn" onclick="upgradeMinerRate('TEMPLATE_ID')">ارتقا دریافت</button>
            <button class="btn" onclick="upgradeMinerCapacity('TEMPLATE_ID')">ارتقا ظرفیت</button>
            <button class="btn" onclick="setCustomName('TEMPLATE_ID')">تغییر نام</button>
            <button class="btn buy-btn" onclick="collectMiner('TEMPLATE_ID')">برداشت</button>
            <button class="btn sell-btn" onclick="sellMiner('TEMPLATE_ID')">فروش به سایت</button>
        </div>
    </div>
</div>

<!-- بخش ایجاد حساب هلپری - فقط ادمین -->
<div id="createHelper" class="section">
    <h1>ایجاد حساب هلپری</h1>
    <div style="background:#ffffff11; padding:30px; border-radius:20px; max-width:500px; margin:30px auto;">
        <p style="margin-bottom:20px; color:#ff9800;">فقط ادمین می‌تواند حساب هلپری بسازد.</p>
        <input type="text" id="helperUsername" placeholder="نام کاربری هلپر" style="width:90%; margin:10px;"><br>
        <input type="password" id="helperPass" placeholder="رمز عبور هلپر" style="width:90%; margin:10px;"><br>
        <button class="btn" style="background:#4CAF50;" onclick="createHelperAccount()">ایجاد حساب هلپری</button>
    </div>
</div>

<!-- داشبورد اختصاصی هلپر -->
<div id="helperDashboard" class="section">
    <h1>داشبورد هلپر <span id="helperUsernameDisplay"></span></h1>
    <div class="dashboard-grid">
        <button class="btn" onclick="showSection('adminReports')">مشاهده گزارشات</button>
        <button class="btn" onclick="showSection('sendMessage')">ارسال پیام</button>
        <button class="btn" onclick="openChat()">صحبت با اعضای سایت</button>
    </div>
</div>

<!-- بخش نمایش اسپانسرها برای کاربران عادی -->
<div id="sponsors" class="section">
    <h1>اسپانسر ها</h1>
    <div id="sponsorsList" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; padding: 20px;">
        <!-- اسپانسرها اینجا به صورت داینامیک اضافه می‌شن -->
        <div style="text-align:center; padding:50px; background:#ffffff11; border-radius:20px;">
            <p style="font-size:24px; color:#ff9800;">هنوز اسپانسری ثبت نشده است.</p>
        </div>
    </div>
</div>

<!-- بخش فرم ثبت اسپانسر برای ادمین -->
<div id="addSponsor" class="section">
    <h1>ثبت اسپانسر جدید</h1>
    <div style="background:#ffffff11; padding:30px; border-radius:20px; max-width:600px; margin:30px auto;">
        <input type="text" id="sponsorName" placeholder="نام اسپانسر (الزامی)" style="width:100%; margin:10px 0; padding:15px; border-radius:15px; background:#ffffff22; color:#fff; border:none;"><br>
        <textarea id="sponsorDesc" placeholder="توضیحات (اختیاری)" style="width:100%; height:100px; margin:10px 0; padding:15px; border-radius:15px; background:#ffffff22; color:#fff; border:none; resize:vertical;"></textarea><br>
        <input type="text" id="sponsorLink" placeholder="لینک کامل (الزامی، با https://)" style="width:100%; margin:10px 0; padding:15px; border-radius:15px; background:#ffffff22; color:#fff; border:none;"><br>
        <button class="btn" style="background:#4CAF50; width:100%; padding:15px;" onclick="addSponsor()">ثبت اسپانسر</button>
    </div>
</div>

<!-- بخش مدیریت وضعیت اسپانسرها - فقط ادمین -->
<div id="adminSponsors" class="section">
    <h1>وضعیت اسپانسر ها</h1>
    <div id="sponsorsAdminList" style="padding:20px;">
        <table style="width:100%; border-collapse:collapse; margin-top:20px;">
            <tr style="background:#333;"><th>زمان انتشار</th><th>تعداد مشاهده</th><th>نام اسپانسر</th><th>عملیات</th></tr>
            <!-- ردیف‌ها اینجا داینامیک اضافه می‌شن -->
        </table>
        <p style="text-align:center; color:#aaa; margin-top:30px;">هیچ اسپانسری ثبت نشده است.</p>
    </div>
</div>



<!-- Overlay لودینگ برای بازدید اسپانسر - نسخه بهبود یافته -->
<div id="sponsorLoadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.85); z-index: 9999; justify-content: center; align-items: center; flex-direction: column; color: white; font-size: 24px; text-align: center; cursor: pointer;" onclick="closeSponsorOverlay()">
    
    <div id="sponsorLoadingContent">
        <div style="background: #ff9800; padding: 25px 50px; border-radius: 25px; box-shadow: 0 0 30px rgba(255, 152, 0, 0.9);">
            <p style="margin: 0; font-weight: bold; font-size: 28px;">کمی صبر کنید ...</p>
            <p style="margin: 15px 0 0; font-size: 20px;">در حال انتقال به سایت اسپانسر</p>
        </div>
        <p style="margin-top: 40px; font-size: 18px; opacity: 0.9;">پس از بازدید می‌توانید به بازی برگردید</p>
    </div>

    <!-- دکمه بستن - در ابتدا مخفی است -->
    <button id="sponsorCloseBtn" style="display: none; margin-top: 30px; padding: 15px 40px; font-size: 20px; background: #f44336; border: none; border-radius: 15px; cursor: pointer; box-shadow: 0 0 20px rgba(244, 67, 54, 0.8);" onclick="event.stopPropagation(); closeSponsorOverlay();">
        بستن
    </button>
</div>

<!-- Loading Spinner Overlay - برای نمایش لودینگ اولیه -->
<div id="loadingOverlay">
    <div class="loader"></div>
    <p>در حال بارگذاری...<br><span style="font-size:18px; opacity:0.8;">لطفاً صبر کنید</span></p>
</div>

</body>
</html>