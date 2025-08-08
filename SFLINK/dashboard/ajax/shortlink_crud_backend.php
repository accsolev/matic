<?php
// --- EDIT SHORTLINK ---
if (isset($_POST['edit_id']) && isset($_POST['edit_urls'])) {
    $edit_id = intval($_POST['edit_id']);
    $new_urls = array_filter(array_map('trim', explode("\n", $_POST['edit_urls'])));
    $fallback_urls = array_filter(array_map('trim', explode("\n", $_POST['edit_fallbacks'] ?? '')));
    $white_page = trim($_POST['edit_white_page'] ?? '');
    $allowed_countries = trim($_POST['edit_allowed_countries'] ?? '');
    $blocked_countries = trim($_POST['edit_blocked_countries'] ?? '');

    // Device targeting
    $device_types = $_POST['edit_device_type'] ?? [];
    $device_urls  = $_POST['edit_device_url'] ?? [];

    // === Tambahan fitur arahkan ke main domain ===
    $useMainDomain = !empty($_POST['modalUseMainDomain']);
    $mainDomainId  = isset($_POST['modalMainDomainId']) ? intval($_POST['modalMainDomainId']) : 0;

    $errorMessage = '';
    $successMessage = '';

    if ($useMainDomain && $mainDomainId) {
        // Ambil nama main domain dari DB
        $stmt = $pdo->prepare("SELECT domain FROM main_domains WHERE id = ? AND user_id = ?");
        $stmt->execute([$mainDomainId, $_SESSION['user_id']]);
        $md = $stmt->fetch();

        if ($md && $md['domain']) {
            $mainDomain = $md['domain'];

            // Ambil hanya PATH saja dari destination & fallback, simpan ke links
            $path_urls = array_map(function($url) {
                $url = trim($url);
                if (!$url) return '';
                $u = parse_url($url, PHP_URL_PATH) ?: '/';
                $q = parse_url($url, PHP_URL_QUERY);
                $h = parse_url($url, PHP_URL_FRAGMENT);
                $out = $u ?: '/';
                if ($q) $out .= '?' . $q;
                if ($h) $out .= '#' . $h;
                return $out;
            }, $new_urls);

            $fallback_path_urls = array_map(function($url) {
                $url = trim($url);
                if (!$url) return '';
                $u = parse_url($url, PHP_URL_PATH) ?: '/';
                $q = parse_url($url, PHP_URL_QUERY);
                $h = parse_url($url, PHP_URL_FRAGMENT);
                $out = $u ?: '/';
                if ($q) $out .= '?' . $q;
                if ($h) $out .= '#' . $h;
                return $out;
            }, $fallback_urls);

            // Update links: simpan main domain, flag, path (as JSON), kosongkan redirect_urls dan fallback_urls
            $pdo->prepare("UPDATE links SET use_main_domain = 1, main_domain_id = ?, path_url = ?, fallback_path_url = ?, white_page_url = ?, allowed_countries = ?, blocked_countries = ? WHERE id = ?")
                ->execute([
                    $mainDomainId,
                    json_encode($path_urls),
                    json_encode($fallback_path_urls),
                    $white_page,
                    $allowed_countries,
                    $blocked_countries,
                    $edit_id
                ]);
            // Hapus redirect/fallback lama (opsional, untuk clean)
            $pdo->prepare("DELETE FROM redirect_urls WHERE link_id = ?")->execute([$edit_id]);
            $pdo->prepare("DELETE FROM fallback_urls WHERE link_id = ?")->execute([$edit_id]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'Main domain tidak ditemukan di database.'
            ]);
            exit;
        }
    } else {
        // === MODE BIASA TANPA MAIN DOMAIN ===

        if (empty($new_urls)) {
            $errorMessage = "❌ URL tidak boleh kosong.";
        } else {
            $isMalicious = false;

            // 🔥 Validasi redirect URLs
            foreach ($new_urls as $url) {
                $original = $url;
                if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                if (!isValidUrl($url)) {
                    $errorMessage = "❌ URL redirect tidak valid: <code>$original</code>";
                    $isMalicious = true;
                    break;
                }
                if (containsMaliciousPayload($url)) {
                    $errorMessage = "❌ URL redirect mengandung kode berbahaya: <code>$original</code>";
                    $isMalicious = true;
                    break;
                }
                if (!isValidDomainFormat($url)) {
                    $errorMessage = "❌ URL destination bukan domain yang sah: <code>$original</code>";
                    $isMalicious = true;
                    break;
                }
            }

            // 🔥 Validasi fallback URLs
            if (!$isMalicious) {
                foreach ($fallback_urls as $url) {
                    $original = $url;
                    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                    if (!isValidUrl($url)) {
                        $errorMessage = "❌ Fallback URL tidak valid: <code>$original</code>";
                        $isMalicious = true;
                        break;
                    }
                    if (containsMaliciousPayload($url)) {
                        $errorMessage = "❌ Fallback URL mengandung kode berbahaya: <code>$original</code>";
                        $isMalicious = true;
                        break;
                    }
                    if (!isValidDomainFormat($url)) {
                        $errorMessage = "❌ Fallback URL bukan domain yang sah: <code>$original</code>";
                        $isMalicious = true;
                        break;
                    }
                }
            }

            // 🔄 Update jika aman
            if (!$isMalicious) {
                // Update destination & fallback
                $pdo->prepare("DELETE FROM redirect_urls WHERE link_id = ?")->execute([$edit_id]);
                foreach ($new_urls as $url) {
                    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                    $pdo->prepare("INSERT INTO redirect_urls (link_id, url) VALUES (?, ?)")->execute([$edit_id, $url]);
                }

                $pdo->prepare("DELETE FROM fallback_urls WHERE link_id = ?")->execute([$edit_id]);
                foreach ($fallback_urls as $url) {
                    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                    $pdo->prepare("INSERT INTO fallback_urls (link_id, url) VALUES (?, ?)")->execute([$edit_id, $url]);
                }

                // Reset mode main domain di table links
                $pdo->prepare("UPDATE links SET use_main_domain = 0, main_domain_id = NULL, path_url = NULL, fallback_path_url = NULL, white_page_url = ?, allowed_countries = ?, blocked_countries = ? WHERE id = ?")
                    ->execute([
                        $white_page,
                        $allowed_countries,
                        $blocked_countries,
                        $edit_id
                    ]);
            }
        }
    }

    // Device targeting tetap update (boleh digabung atas/bawah)
    $pdo->prepare("DELETE FROM device_targets WHERE link_id = ?")->execute([$edit_id]);
    if (!empty($device_types) && is_array($device_types)) {
        foreach ($device_types as $i => $type) {
            $durl = trim($device_urls[$i] ?? '');
            if ($type && $durl) {
                if (!preg_match("~^(?:f|ht)tps?://~i", $durl)) $durl = "http://$durl";
                $pdo->prepare("INSERT INTO device_targets (link_id, device_type, url) VALUES (?, ?, ?)")
                    ->execute([$edit_id, strtolower($type), $durl]);
            }
        }
    }

    $successMessage = "✅ Shortlink berhasil diupdate!";
    logActivity($pdo, $_SESSION['user_id'], $_SESSION['username'], "Mengedit link ID #$edit_id (all fields)");

    echo json_encode([
        'success' => !$errorMessage,
        'message' => $errorMessage ?: $successMessage
    ]);
    exit;
}

// Hapus shortlink
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $stmt = $pdo->prepare("SELECT l.short_code, d.domain FROM links l JOIN domains d ON l.domain_id = d.id WHERE l.id = ? AND l.user_id = ?");
    $stmt->execute([$id, $userId]);
    $link = $stmt->fetch();

    if ($link) {
        $fullUrl = $link['domain'] . '/' . $link['short_code'];

        $stmt = $pdo->prepare("DELETE FROM links WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $userId]);

        $successMessage = "Shortlink berhasil dihapus: <code>$fullUrl</code>";
        sendTelegramNotif($userId, $pdo, "🗑️ Shortlink dihapus: https://$fullUrl");
        logActivity($pdo, $userId, $username, "Menghapus shortlink: https://$fullUrl");
    } else {
        $errorMessage = "❌ Shortlink tidak ditemukan atau bukan milik Anda.";
    }
}
// --- CEK LIMIT USER ---
$stmt = $pdo->prepare("SELECT type FROM users WHERE id = ?");
$stmt->execute([$userId]);
$userType = $stmt->fetchColumn();

// --- TAMBAH SHORTLINK ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['edit_id'])) {
    // Limit per tipe user
    $limitMap = [
        'trial' => 1,
        'medium' => 3,
        'vip' => 30,
        'vipmax' => PHP_INT_MAX
    ];
    $maxLinkAllowed = $limitMap[$userType] ?? 0;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE user_id = ?");
    $stmt->execute([$userId]);
    $linkCount = $stmt->fetchColumn();

     if ($linkCount >= $maxLinkAllowed) {
        $errorMessage = match ($userType) {
            'trial' => "Akun trial hanya bisa membuat 1 shortlink. Upgrade untuk akses lebih banyak.",
            'medium' => "Akun medium hanya bisa membuat maksimal 3 shortlink. Upgrade ke VIP/VIP MAX untuk akses penuh.",
            'vip' => "Akun vip hanya bisa membuat maksimal 30 shortlink. Upgrade ke VIP MAX untuk akses penuh.",
            default => "❌ Batas pembuatan shortlink telah tercapai."
        };
    }

    if (!$errorMessage) {
        $urls = array_filter(array_map('trim', explode("\n", $_POST['urls'] ?? '')));
        $fallbackUrls = array_filter(array_map('trim', explode("\n", $_POST['fallback_urls'] ?? '')));
        $alias = trim($_POST['alias'] ?? '');
        $domain = trim($_POST['domain'] ?? '');

        // ✅ Tambahan fitur country filter dan white page
        $whitePage = trim($_POST['white_page'] ?? '');
        $allowedCountries = implode(',', array_filter(array_map('strtoupper', explode("\n", $_POST['allowed_countries'] ?? ''))));
        $blockedCountries = implode(',', array_filter(array_map('strtoupper', explode("\n", $_POST['blocked_countries'] ?? ''))));

        if (!isset($domains)) {
            $domains = $pdo->query("SELECT domain FROM domains")->fetchAll(PDO::FETCH_COLUMN);
        }

        if (empty($urls) && empty($fallbackUrls)) {
            $errorMessage = "❌ URL tujuan dan fallback URL tidak boleh kosong semua.";
        } elseif ($alias && !isValidAlias($alias)) {
            $errorMessage = "❌ Alias tidak valid! Hanya huruf, angka, - dan _ (3-30 karakter).";
        } elseif (!in_array($domain, $domains)) {
            $errorMessage = "❌ Domain tidak valid.";
        } else {
            if ($alias) {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM links WHERE short_code = ? AND domain_id = (SELECT id FROM domains WHERE domain = ?)");
                $stmt->execute([$alias, $domain]);
                if ($stmt->fetchColumn()) {
                    $errorMessage = "❌ Alias <b>$alias</b> sudah digunakan pada domain <b>$domain</b>.";
                }
            }

            if (!$errorMessage) {
                $isMalicious = false;

                // 🔍 Validasi destination
                foreach ($urls as $url) {
                    $original = $url;
                    if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                    if (!isValidUrl($url) || containsMaliciousPayload($url) || !isValidDomainFormat($url)) {
                        $errorMessage = "❌ URL tidak valid atau berbahaya: <code>$original</code>";
                        $isMalicious = true;
                        break;
                    }
                }

                // 🔍 Validasi fallback
                if (!$isMalicious) {
                    foreach ($fallbackUrls as $fbUrl) {
                        $original = $fbUrl;
                        if (!preg_match("~^(?:f|ht)tps?://~i", $fbUrl)) $fbUrl = "http://$fbUrl";
                        if (!isValidUrl($fbUrl) || containsMaliciousPayload($fbUrl) || !isValidDomainFormat($fbUrl)) {
                            $errorMessage = "❌ Fallback URL tidak valid atau berbahaya: <code>$original</code>";
                            $isMalicious = true;
                            break;
                        }
                    }
                }

                if (!$isMalicious) {
                    $domainStmt = $pdo->prepare("SELECT id FROM domains WHERE domain = ?");
                    $domainStmt->execute([$domain]);
                    $domainData = $domainStmt->fetch();

                    if (!$domainData) {
                        $errorMessage = "Domain tidak ditemukan di database.";
                    } else {
                        $domainId = $domainData['id'];
                        $shortCode = $alias ?: substr(md5(uniqid()), 0, 6);
                        $created_at = date("Y-m-d H:i:s");

                        // ✅ Insert link dengan filter negara dan whitepage
                        $stmt = $pdo->prepare("
                            INSERT INTO links 
                            (user_id, short_code, domain_id, created_at, white_page_url, allowed_countries, blocked_countries) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $userId, $shortCode, $domainId, $created_at,
                            $whitePage, $allowedCountries, $blockedCountries
                        ]);
                        $linkId = $pdo->lastInsertId();

                        // 🔗 Insert redirect
                        foreach ($urls as $url) {
                            if (!preg_match("~^(?:f|ht)tps?://~i", $url)) $url = "http://$url";
                            $pdo->prepare("INSERT INTO redirect_urls (link_id, url) VALUES (?, ?)")->execute([$linkId, $url]);
                        }

                        // 🔗 Insert fallback
                        foreach ($fallbackUrls as $fbUrl) {
                            if (!preg_match("~^(?:f|ht)tps?://~i", $fbUrl)) $fbUrl = "http://$fbUrl";
                            $pdo->prepare("INSERT INTO fallback_urls (link_id, url) VALUES (?, ?)")->execute([$linkId, $fbUrl]);
                        }

                        // 📱 Device targeting
                        if (!empty($_POST['device_type']) && is_array($_POST['device_type'])) {
                            foreach ($_POST['device_type'] as $i => $deviceType) {
                                $deviceUrl = trim($_POST['device_url'][$i] ?? '');
                                if ($deviceType && $deviceUrl) {
                                    if (!preg_match("~^(?:f|ht)tps?://~i", $deviceUrl)) $deviceUrl = "http://$deviceUrl";
                                    $pdo->prepare("INSERT INTO device_targets (link_id, device_type, url) VALUES (?, ?, ?)")
                                        ->execute([$linkId, strtolower($deviceType), $deviceUrl]);
                                }
                            }
                        }

                        $successMessage = "Shortlink berhasil dibuat: <code>https://$domain/$shortCode</code>";
                        sendTelegramNotif($userId, $pdo, "🔗 Anda telah membuat shortlink: https://$domain/$shortCode");
                        logActivity($pdo, $userId, $username, "Membuat shortlink: https://$domain/$shortCode");
                    }
                }
            }
        }
    }
}

?>
