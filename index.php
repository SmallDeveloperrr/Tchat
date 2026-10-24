<?php
declare(strict_types=1);
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params(['httponly'=>true,'samesite'=>'Lax','secure'=>(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),'path'=>'/']);
}
session_start();

/*
 * Tchat — single-file PHP + MySQL chat
 * PHP 8.1+, PDO_MySQL, Fileinfo, HTTPS recommended/required for media.
 * Replace the four DB constants below with your hosting credentials.
 */
const DB_HOST = 'sql108.infinityfree.com';
const DB_NAME = 'if0_43255225_Tchat';
const DB_USER = 'if0_43255225';
const DB_PASS = 'Aj100900Aj';
const MAX_TEXT = 4000;
const MAX_AUDIO = 5_242_880;

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(self), microphone=(self), display-capture=(self)');
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));

try {
    $db = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(24) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen TIMESTAMP NULL DEFAULT NULL,
        INDEX username_idx(username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        sender_id BIGINT UNSIGNED NOT NULL,
        receiver_id BIGINT UNSIGNED NOT NULL,
        body TEXT NULL,
        audio_file VARCHAR(80) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX chat_a(sender_id,receiver_id,id), INDEX chat_b(receiver_id,sender_id,id),
        CONSTRAINT msg_sender FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT msg_receiver FOREIGN KEY(receiver_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS recent_chats (
        user_id BIGINT UNSIGNED NOT NULL,
        other_id BIGINT UNSIGNED NOT NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY(user_id,other_id), INDEX recent_order(user_id,updated_at),
        CONSTRAINT recent_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT recent_other_fk FOREIGN KEY(other_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS chat_groups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(60) NOT NULL,
        owner_id BIGINT UNSIGNED NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX group_owner(owner_id),
        CONSTRAINT group_owner_fk FOREIGN KEY(owner_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS group_members (
        group_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY(group_id,user_id), INDEX member_user(user_id),
        CONSTRAINT gm_group_fk FOREIGN KEY(group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
        CONSTRAINT gm_user_fk FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE IF NOT EXISTS group_messages (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        group_id BIGINT UNSIGNED NOT NULL,
        sender_id BIGINT UNSIGNED NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX group_msg_order(group_id,id),
        CONSTRAINT gmsg_group_fk FOREIGN KEY(group_id) REFERENCES chat_groups(id) ON DELETE CASCADE,
        CONSTRAINT gmsg_sender_fk FOREIGN KEY(sender_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Repair legacy groups whose creator was never inserted into group_members.
    $db->exec('INSERT IGNORE INTO group_members(group_id,user_id) SELECT id,owner_id FROM chat_groups');
    $db->exec("CREATE TABLE IF NOT EXISTS calls (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        caller_id BIGINT UNSIGNED NOT NULL,
        callee_id BIGINT UNSIGNED NOT NULL,
        call_type ENUM('audio','video','screen') NOT NULL DEFAULT 'audio',
        status ENUM('ringing','accepted','ended','rejected') NOT NULL DEFAULT 'ringing',
        offer_sdp MEDIUMTEXT NULL,
        answer_sdp MEDIUMTEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX call_callee(callee_id,status,id), INDEX call_caller(caller_id,status,id),
        CONSTRAINT call_caller_fk FOREIGN KEY(caller_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT call_callee_fk FOREIGN KEY(callee_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    http_response_code(500);
    exit('اتصال دیتابیس برقرار نشد. مشخصات MySQL و فعال بودن PDO MySQL را بررسی کنید.');
}

function jsonOut(array $data, int $code=200): never {
    http_response_code($code); header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit;
}
function payload(): array {
    $v = json_decode(file_get_contents('php://input') ?: '{}', true); return is_array($v) ? $v : [];
}
function uid(): int { return (int)($_SESSION['uid'] ?? 0); }
function needUser(): int { if (!uid()) jsonOut(['ok'=>false,'error'=>'ابتدا وارد حساب شوید.'],401); return uid(); }
function csrfCheck(): void {
    $got = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($got) || !hash_equals((string)($_SESSION['csrf'] ?? ''),$got)) jsonOut(['ok'=>false,'error'=>'توکن امنیتی نامعتبر است. صفحه را تازه کنید.'],403);
}
function userExists(PDO $db,int $id): bool { $s=$db->prepare('SELECT id FROM users WHERE id=?');$s->execute([$id]);return (bool)$s->fetchColumn(); }
function getCall(PDO $db,int $id,int $callId): array {
    $s=$db->prepare('SELECT * FROM calls WHERE id=? AND (caller_id=? OR callee_id=?)');$s->execute([$callId,$id,$id]);$r=$s->fetch();
    if(!$r) jsonOut(['ok'=>false,'error'=>'تماس پیدا نشد.'],404); return $r;
}
$action = (string)($_GET['action'] ?? '');
if ($action !== '') {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $getAllowed = ['me','messages','chats','groups','search','group-messages','audio','call-poll','call-incoming'];
    if ($method !== 'POST' && !in_array($action,$getAllowed,true)) jsonOut(['ok'=>false,'error'=>'روش درخواست نامعتبر است.'],405);
    if ($method === 'POST') csrfCheck();
    try {
        if ($action === 'me') {
            $id=uid(); if(!$id) jsonOut(['ok'=>true,'user'=>null,'csrf'=>$_SESSION['csrf']]);
            $s=$db->prepare('SELECT id,username FROM users WHERE id=?');$s->execute([$id]);
            jsonOut(['ok'=>true,'user'=>$s->fetch(),'csrf'=>$_SESSION['csrf']]);
        }
        if ($action === 'register') {
            $d=payload();$name=trim((string)($d['username']??''));$pass=(string)($d['password']??'');
            if(!preg_match('/^[a-zA-Z0-9_]{3,24}$/',$name)) jsonOut(['ok'=>false,'error'=>'نام کاربری باید ۳ تا ۲۴ حرف انگلیسی، عدد یا _ باشد.'],422);
            if(strlen($pass)<8||strlen($pass)>200) jsonOut(['ok'=>false,'error'=>'رمز عبور باید حداقل ۸ کاراکتر باشد.'],422);
            try{$s=$db->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)');$s->execute([$name,password_hash($pass,PASSWORD_DEFAULT)]);}catch(PDOException $e){if($e->getCode()==='23000')jsonOut(['ok'=>false,'error'=>'این نام کاربری قبلاً گرفته شده است.'],409);throw $e;}
            session_regenerate_id(true);$_SESSION['uid']=(int)$db->lastInsertId();$_SESSION['csrf']=bin2hex(random_bytes(32));jsonOut(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
        }
        if ($action === 'login') {
            $d=payload();$name=trim((string)($d['username']??''));$pass=(string)($d['password']??'');
            $s=$db->prepare('SELECT id,password_hash FROM users WHERE username=?');$s->execute([$name]);$u=$s->fetch();
            if(!$u||!password_verify($pass,$u['password_hash']))jsonOut(['ok'=>false,'error'=>'نام کاربری یا رمز عبور اشتباه است.'],401);
            session_regenerate_id(true);$_SESSION['uid']=(int)$u['id'];$_SESSION['csrf']=bin2hex(random_bytes(32));
            $db->prepare('UPDATE users SET last_seen=NOW() WHERE id=?')->execute([uid()]);jsonOut(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
        }
        if ($action === 'logout') { $_SESSION=[];if(ini_get('session.use_cookies')){$c=session_get_cookie_params();setcookie(session_name(),'',(int)(time()-3600),$c['path'],$c['domain'],$c['secure'],$c['httponly']);}session_destroy();jsonOut(['ok'=>true]); }
        $me=needUser();
        if ($action === 'search') {
            $term=trim((string)(payload()['q']??''));if($term===''||strlen($term)>24)jsonOut(['ok'=>true,'users'=>[]]);
            $escaped=str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$term);
            $s=$db->prepare("SELECT id,username FROM users WHERE username LIKE ? ESCAPE '\\\\' AND id<>? ORDER BY username LIMIT 25");$s->execute(['%'.$escaped.'%',$me]);jsonOut(['ok'=>true,'users'=>$s->fetchAll()]);
        }
        if ($action === 'groups') {
            // Make sure old groups are not hidden just because the owner-member row was missing.
            $db->exec('INSERT IGNORE INTO group_members(group_id,user_id) SELECT id,owner_id FROM chat_groups');
            $s=$db->prepare("SELECT g.id,g.name,g.owner_id,COALESCE(MAX(m.id),0) AS last_id,COUNT(DISTINCT gm2.user_id) AS member_count FROM chat_groups g JOIN group_members mine ON mine.group_id=g.id AND mine.user_id=? LEFT JOIN group_members gm2 ON gm2.group_id=g.id LEFT JOIN group_messages m ON m.group_id=g.id GROUP BY g.id,g.name,g.owner_id ORDER BY last_id DESC,g.id DESC LIMIT 100");
            $s->execute([$me]); $rows=$s->fetchAll();
            foreach($rows as &$row){$row['id']=(int)$row['id'];$row['owner_id']=(int)$row['owner_id'];$row['member_count']=(int)$row['member_count'];} unset($row);
            jsonOut(['ok'=>true,'groups'=>$rows]);
        }
        if ($action === 'group-create') {
            $d=payload();$name=trim((string)($d['name']??''));$members=$d['members']??[];
            if($name===''||mb_strlen($name,'UTF-8')>60)jsonOut(['ok'=>false,'error'=>'نام گروه باید بین ۱ تا ۶۰ نویسه باشد.'],422);
            if(!is_array($members))jsonOut(['ok'=>false,'error'=>'فهرست اعضا نامعتبر است.'],422);
            if(count($members)>49)jsonOut(['ok'=>false,'error'=>'حداکثر ۵۰ عضو در هر گروه مجاز است.'],422);
            $db->beginTransaction();
            try {
                $s=$db->prepare('INSERT INTO chat_groups(name,owner_id) VALUES(?,?)');$s->execute([$name,$me]);$gid=(int)$db->lastInsertId();
                $add=$db->prepare('INSERT IGNORE INTO group_members(group_id,user_id) VALUES(?,?)');$add->execute([$gid,$me]);
                $findById=$db->prepare('SELECT id,username FROM users WHERE id=?');
                $findByName=$db->prepare('SELECT id,username FROM users WHERE username=?');
                $notFound=[];
                foreach(array_values(array_unique($members,SORT_REGULAR)) as $value){
                    $value=trim((string)$value); if($value==='')continue;
                    if(ctype_digit($value)){$findById->execute([(int)$value]);$stmt=$findById;}
                    elseif(preg_match('/^[a-zA-Z0-9_]{3,24}$/',$value)){$findByName->execute([$value]);$stmt=$findByName;}
                    else {$notFound[]=$value;continue;}
                    $u=$stmt->fetch();
                    if(!$u || (int)$u['id']===$me){if(!$u)$notFound[]=$value;continue;}
                    $add->execute([$gid,(int)$u['id']]);
                }
                $db->commit();
            } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
            jsonOut(['ok'=>true,'group'=>['id'=>$gid,'name'=>$name,'owner_id'=>$me],'not_found'=>$notFound]);
        }
        if ($action === 'group-add') {
            $d=payload();$gid=(int)($d['group']??0);$members=$d['members']??[];
            if(!is_array($members)||!$gid)jsonOut(['ok'=>false,'error'=>'اطلاعات گروه نامعتبر است.'],422);
            $check=$db->prepare('SELECT owner_id FROM chat_groups WHERE id=?');$check->execute([$gid]);$group=$check->fetch();
            if(!$group)jsonOut(['ok'=>false,'error'=>'گروه پیدا نشد.'],404);
            $memberCheck=$db->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');$memberCheck->execute([$gid,$me]);
            if(!$memberCheck->fetchColumn())jsonOut(['ok'=>false,'error'=>'ابتدا باید عضو گروه باشی.'],403);
            if(!$members||count($members)>49)jsonOut(['ok'=>false,'error'=>'حداقل یک عضو و حداکثر ۴۹ عضو را انتخاب کن.'],422);
            $added=[];$already=[];$notFound=[];
            $findById=$db->prepare('SELECT id,username FROM users WHERE id=?');$findByName=$db->prepare('SELECT id,username FROM users WHERE username=?');
            $exists=$db->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');$add=$db->prepare('INSERT IGNORE INTO group_members(group_id,user_id) VALUES(?,?)');
            foreach(array_values(array_unique($members,SORT_REGULAR)) as $value){
                $value=trim((string)$value);if($value==='')continue;
                if(ctype_digit($value)){$findById->execute([(int)$value]);$stmt=$findById;}
                elseif(preg_match('/^[a-zA-Z0-9_]{3,24}$/',$value)){$findByName->execute([$value]);$stmt=$findByName;}
                else{$notFound[]=$value;continue;}
                $u=$stmt->fetch();if(!$u||(int)$u['id']===$me){if(!$u)$notFound[]=$value;continue;}
                $userId=(int)$u['id'];$exists->execute([$gid,$userId]);
                if($exists->fetchColumn()){$already[]=$u['username'];continue;}
                $add->execute([$gid,$userId]);if($add->rowCount())$added[]=$u['username'];
            }
            jsonOut(['ok'=>true,'added'=>$added,'already'=>$already,'not_found'=>$notFound]);
        }
        if ($action === 'group-leave') {
            $d=payload();
            $gid=(int)($d['group']??0);
            if(!$gid) jsonOut(['ok'=>false,'error'=>'گروه نامعتبر است.'],422);
            $db->beginTransaction();
            try {
                $s=$db->prepare('SELECT id,owner_id,name FROM chat_groups WHERE id=? FOR UPDATE');
                $s->execute([$gid]); $group=$s->fetch();
                if(!$group) { $db->rollBack(); jsonOut(['ok'=>false,'error'=>'گروه پیدا نشد.'],404); }
                $m=$db->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');
                $m->execute([$gid,$me]);
                if(!$m->fetchColumn()) { $db->rollBack(); jsonOut(['ok'=>false,'error'=>'عضو این گروه نیستی.'],403); }
                $db->prepare('DELETE FROM group_members WHERE group_id=? AND user_id=?')->execute([$gid,$me]);
                $left=$db->prepare('SELECT user_id FROM group_members WHERE group_id=? ORDER BY joined_at ASC,user_id ASC LIMIT 1');
                $left->execute([$gid]); $nextOwner=(int)($left->fetchColumn()?:0);
                if($nextOwner===0) {
                    $db->prepare('DELETE FROM chat_groups WHERE id=?')->execute([$gid]);
                    $db->commit();
                    jsonOut(['ok'=>true,'deleted'=>true,'message'=>'گروه خالی بود و حذف شد.']);
                }
                if((int)$group['owner_id']===$me) {
                    $db->prepare('UPDATE chat_groups SET owner_id=? WHERE id=?')->execute([$nextOwner,$gid]);
                }
                $db->commit();
                jsonOut(['ok'=>true,'deleted'=>false,'message'=>((int)$group['owner_id']===$me?'از گروه خارج شدی؛ مالکیت به یکی از اعضا منتقل شد.':'با موفقیت از گروه خارج شدی.')]);
            } catch(Throwable $e) {
                if($db->inTransaction()) $db->rollBack();
                throw $e;
            }
        }
        if ($action === 'group-messages') {
            $gid=(int)($_GET['group']??0);$after=max(0,(int)($_GET['after']??0));
            $m=$db->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');$m->execute([$gid,$me]);if(!$m->fetchColumn())jsonOut(['ok'=>false,'error'=>'عضو این گروه نیستی.'],403);
            $s=$db->prepare('SELECT gm.id,gm.sender_id,gm.body,gm.created_at,u.username FROM group_messages gm JOIN users u ON u.id=gm.sender_id WHERE gm.group_id=? AND gm.id>? ORDER BY gm.id ASC LIMIT 100');$s->execute([$gid,$after]);$rows=$s->fetchAll();foreach($rows as &$row){$row['id']=(int)$row['id'];$row['sender_id']=(int)$row['sender_id'];}unset($row);jsonOut(['ok'=>true,'messages'=>$rows]);
        }
        if ($action === 'group-send') {
            $d=payload();$gid=(int)($d['group']??0);$body=trim((string)($d['body']??''));if($body===''||mb_strlen($body,'UTF-8')>MAX_TEXT)jsonOut(['ok'=>false,'error'=>'پیام خالی است یا بیش از حد طولانی است.'],422);
            $m=$db->prepare('SELECT 1 FROM group_members WHERE group_id=? AND user_id=?');$m->execute([$gid,$me]);if(!$m->fetchColumn())jsonOut(['ok'=>false,'error'=>'عضو این گروه نیستی.'],403);
            $s=$db->prepare('INSERT INTO group_messages(group_id,sender_id,body) VALUES(?,?,?)');$s->execute([$gid,$me,$body]);jsonOut(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
        }
        if ($action === 'chat-open') {
            $d=payload();$other=(int)($d['with']??0);
            if(!$other||$other===$me||!userExists($db,$other))jsonOut(['ok'=>false,'error'=>'کاربر نامعتبر است.'],422);
            $s=$db->prepare('INSERT INTO recent_chats(user_id,other_id) VALUES(?,?) ON DUPLICATE KEY UPDATE updated_at=CURRENT_TIMESTAMP');$s->execute([$me,$other]);
            $s=$db->prepare('INSERT INTO recent_chats(user_id,other_id) VALUES(?,?) ON DUPLICATE KEY UPDATE updated_at=CURRENT_TIMESTAMP');$s->execute([$other,$me]);
            jsonOut(['ok'=>true]);
        }
        if ($action === 'chats') {
            $s=$db->prepare("SELECT u.id,u.username,MAX(COALESCE(m.id,0)) last_id,MAX(COALESCE(rc.updated_at,'1970-01-01 00:00:00')) last_open FROM users u LEFT JOIN messages m ON ((m.sender_id=? AND m.receiver_id=u.id) OR (m.sender_id=u.id AND m.receiver_id=?)) LEFT JOIN recent_chats rc ON rc.user_id=? AND rc.other_id=u.id WHERE u.id<>? AND (m.id IS NOT NULL OR rc.user_id IS NOT NULL) GROUP BY u.id,u.username ORDER BY last_id DESC,last_open DESC LIMIT 100");
            $s->execute([$me,$me,$me,$me]);$rows=$s->fetchAll();foreach($rows as &$row){$row['id']=(int)$row['id'];}unset($row);jsonOut(['ok'=>true,'chats'=>$rows]);
        }
        if ($action === 'send') {
            $d=payload();$to=(int)($d['to']??0);$body=trim((string)($d['body']??''));
            if(!$to||$to===$me||!userExists($db,$to))jsonOut(['ok'=>false,'error'=>'کاربر نامعتبر است.'],422);
            if($body===''||mb_strlen($body,'UTF-8')>MAX_TEXT)jsonOut(['ok'=>false,'error'=>'پیام خالی است یا بیش از حد طولانی است.'],422);
            $s=$db->prepare('INSERT INTO messages(sender_id,receiver_id,body) VALUES(?,?,?)');$s->execute([$me,$to,$body]);jsonOut(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
        }
        if ($action === 'messages') {
            $other=(int)($_GET['with']??0);$after=max(0,(int)($_GET['after']??0));if(!$other||$other===$me||!userExists($db,$other))jsonOut(['ok'=>false,'error'=>'گفت‌وگو نامعتبر است.'],422);
            $s=$db->prepare('SELECT id,sender_id,receiver_id,body,audio_file,created_at FROM messages WHERE ((sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)) AND id>? ORDER BY id ASC LIMIT 100');$s->execute([$me,$other,$other,$me,$after]);$rows=$s->fetchAll();
            foreach($rows as &$r){$r['id']=(int)$r['id'];$r['sender_id']=(int)$r['sender_id'];$r['receiver_id']=(int)$r['receiver_id'];$r['audio_url']=$r['audio_file']?'?action=audio&id='.$r['id']:null;unset($r['audio_file']);}unset($r);jsonOut(['ok'=>true,'messages'=>$rows]);
        }
        if ($action === 'voice') {
            $to=(int)($_POST['to']??0);if(!$to||$to===$me||!userExists($db,$to))jsonOut(['ok'=>false,'error'=>'کاربر نامعتبر است.'],422);
            if(!isset($_FILES['audio'])||$_FILES['audio']['error']!==UPLOAD_ERR_OK)jsonOut(['ok'=>false,'error'=>'فایل صوتی دریافت نشد.'],400);
            $f=$_FILES['audio'];if($f['size']<1||$f['size']>MAX_AUDIO)jsonOut(['ok'=>false,'error'=>'حداکثر حجم پیام صوتی ۵ مگابایت است.'],413);
            $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);$types=['audio/webm'=>'webm','video/webm'=>'webm','audio/ogg'=>'ogg','application/ogg'=>'ogg','audio/mp4'=>'m4a','audio/mpeg'=>'mp3','audio/wav'=>'wav'];
            if(!isset($types[$mime]))jsonOut(['ok'=>false,'error'=>'فرمت صوتی پشتیبانی نمی‌شود.'],415);
            $dir=__DIR__.'/tchat_uploads';if(!is_dir($dir)&&!mkdir($dir,0750,true))jsonOut(['ok'=>false,'error'=>'ساخت پوشه آپلود ناموفق بود.'],500);
            if(!file_exists($dir.'/.htaccess'))file_put_contents($dir.'/.htaccess',"Options -Indexes\n<FilesMatch \".*\">\nRequire all denied\n</FilesMatch>\n");
            $file=bin2hex(random_bytes(20)).'.'.$types[$mime];if(!move_uploaded_file($f['tmp_name'],$dir.'/'.$file))jsonOut(['ok'=>false,'error'=>'ذخیره فایل ناموفق بود.'],500);
            $s=$db->prepare('INSERT INTO messages(sender_id,receiver_id,audio_file) VALUES(?,?,?)');$s->execute([$me,$to,$file]);jsonOut(['ok'=>true,'id'=>(int)$db->lastInsertId()]);
        }
        if ($action === 'audio') {
            $mid=(int)($_GET['id']??0);$s=$db->prepare('SELECT * FROM messages WHERE id=? AND (sender_id=? OR receiver_id=?)');$s->execute([$mid,$me,$me]);$m=$s->fetch();if(!$m||!$m['audio_file']){http_response_code(404);exit;}
            $path=__DIR__.'/tchat_uploads/'.basename($m['audio_file']);if(!is_file($path)){http_response_code(404);exit;}$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));$types=['webm'=>'audio/webm','ogg'=>'audio/ogg','m4a'=>'audio/mp4','mp3'=>'audio/mpeg','wav'=>'audio/wav'];header('Content-Type: '.($types[$ext]??'application/octet-stream'));header('Content-Length: '.filesize($path));header('Cache-Control: private, no-store');readfile($path);exit;
        }
        if ($action === 'call-start') {
            $d=payload();$to=(int)($d['to']??0);$kind=(string)($d['kind']??'audio');$offer=(string)($d['offer']??'');
            if(!$to||$to===$me||!userExists($db,$to))jsonOut(['ok'=>false,'error'=>'کاربر تماس معتبر نیست.'],422);
            if(!in_array($kind,['audio','video','screen'],true)||strlen($offer)>2000000||$offer==='')jsonOut(['ok'=>false,'error'=>'اطلاعات تماس نامعتبر است.'],422);
            $s=$db->prepare("UPDATE calls SET status='ended' WHERE status IN ('ringing','accepted') AND (caller_id=? OR callee_id=?)");$s->execute([$me,$me]);
            $s=$db->prepare("INSERT INTO calls(caller_id,callee_id,call_type,status,offer_sdp) VALUES(?,?,?,'ringing',?)");$s->execute([$me,$to,$kind,$offer]);jsonOut(['ok'=>true,'call_id'=>(int)$db->lastInsertId()]);
        }
        if ($action === 'call-incoming') {
            $s=$db->prepare("SELECT c.id,c.caller_id,c.call_type,c.offer_sdp,u.username FROM calls c JOIN users u ON u.id=c.caller_id WHERE c.callee_id=? AND c.status='ringing' ORDER BY c.id DESC LIMIT 1");$s->execute([$me]);jsonOut(['ok'=>true,'call'=>$s->fetch()?:null]);
        }
        if ($action === 'call-answer') {
            $d=payload();$cid=(int)($d['call_id']??0);$answer=(string)($d['answer']??'');if($answer===''||strlen($answer)>2000000)jsonOut(['ok'=>false,'error'=>'پاسخ تماس نامعتبر است.'],422);
            $c=getCall($db,$me,$cid);if((int)$c['callee_id']!==$me||$c['status']!=='ringing')jsonOut(['ok'=>false,'error'=>'این تماس دیگر فعال نیست.'],409);
            $s=$db->prepare("UPDATE calls SET status='accepted',answer_sdp=? WHERE id=? AND callee_id=? AND status='ringing'");$s->execute([$answer,$cid,$me]);jsonOut(['ok'=>true]);
        }
        if ($action === 'call-poll') {
            $cid=(int)($_GET['id']??0);$c=getCall($db,$me,$cid);jsonOut(['ok'=>true,'call'=>['id'=>(int)$c['id'],'caller_id'=>(int)$c['caller_id'],'callee_id'=>(int)$c['callee_id'],'type'=>$c['call_type'],'status'=>$c['status'],'offer'=>$c['offer_sdp'],'answer'=>$c['answer_sdp']]]);
        }
        if ($action === 'call-reject') {
            $cid=(int)(payload()['call_id']??0);$c=getCall($db,$me,$cid);if((int)$c['callee_id']!==$me)jsonOut(['ok'=>false,'error'=>'فقط دریافت‌کننده می‌تواند تماس را رد کند.'],403);$db->prepare("UPDATE calls SET status='rejected' WHERE id=? AND status='ringing'")->execute([$cid]);jsonOut(['ok'=>true]);
        }
        if ($action === 'call-end') {
            $cid=(int)(payload()['call_id']??0);getCall($db,$me,$cid);$db->prepare("UPDATE calls SET status='ended' WHERE id=?")->execute([$cid]);jsonOut(['ok'=>true]);
        }
        jsonOut(['ok'=>false,'error'=>'عملیات ناشناخته است.'],404);
    } catch(Throwable $e) { error_log('Tchat: '.$e->getMessage());jsonOut(['ok'=>false,'error'=>'خطای داخلی سرور. گزارش خطاهای هاست را بررسی کنید.'],500); }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#fff">
<title>Tchat — گفت‌وگوی ساده</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{--bg:#f5f5f5;--white:#fff;--ink:#171717;--muted:#818181;--line:#e9e9e9;--soft:#f1f1f1;--danger:#b42318;--green:#18864b}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px Vazirmatn,Tahoma,sans-serif}button,input,textarea{font:inherit}button{cursor:pointer}button:disabled{opacity:.5;cursor:not-allowed}.app{height:100dvh;max-width:1550px;margin:auto;padding:16px;display:grid;grid-template-columns:330px minmax(0,1fr);gap:16px}.panel{background:var(--white);border:1px solid var(--line);border-radius:22px;min-height:0;overflow:hidden}.sidebar{display:flex;flex-direction:column}.brand{padding:22px;display:flex;align-items:center;gap:12px;border-bottom:1px solid var(--line)}.logo{width:44px;height:44px;border-radius:15px;background:var(--ink);color:#fff;display:grid;place-items:center;font-size:23px;font-weight:800}.brand h1{font-size:23px;letter-spacing:-1px;margin:0;font-weight:800}.brand p{font-size:11px;color:var(--muted);margin:2px 0 0}.userbar{display:flex;align-items:center;gap:10px;padding:14px 17px;border-bottom:1px solid var(--line)}.avatar{width:43px;height:43px;border-radius:50%;background:#ededed;display:grid;place-items:center;font-weight:700;font-size:17px;flex-shrink:0}.userinfo{min-width:0;flex:1}.username{font-weight:700;overflow-wrap:anywhere}.sub{font-size:12px;color:var(--muted);margin-top:3px}.btn{border:0;border-radius:12px;background:var(--ink);color:#fff;padding:10px 14px;font-weight:600}.btn.light{background:var(--soft);color:var(--ink)}.btn.icon{width:41px;height:41px;padding:0;display:grid;place-items:center;font-size:18px}.search{padding:15px 17px}.search input,.field{width:100%;border:1px solid var(--line);border-radius:13px;background:#fafafa;padding:13px;outline:none}.search input:focus,.field:focus{border-color:#999;background:#fff}.section-label{padding:0 20px 10px;color:var(--muted);font-size:12px}.results{flex:1;overflow:auto;padding:0 9px 14px}.person{display:flex;align-items:center;gap:11px;padding:12px;border-radius:14px;cursor:pointer}.person:hover,.person.active{background:#f2f2f2}.empty{padding:25px 15px;text-align:center;color:var(--muted);line-height:2}.notice{margin:12px 16px;padding:11px;border-radius:12px;background:#f6f6f6;color:#666;font-size:11px;line-height:1.9}.chat{display:flex;flex-direction:column;min-width:0}.chat-head{padding:15px 20px;min-height:80px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px}.chat-head .userinfo{flex:1}.actions{display:flex;gap:7px;flex-wrap:wrap}.messages{flex:1;overflow:auto;padding:23px;display:flex;flex-direction:column;gap:11px;background:#fff}.bubble{max-width:min(76%,580px);padding:11px 15px;border-radius:17px;line-height:1.9;white-space:pre-wrap;overflow-wrap:anywhere}.bubble.mine{align-self:flex-start;background:#171717;color:#fff;border-bottom-left-radius:5px}.bubble.theirs{align-self:flex-end;background:#f0f0f0;border-bottom-right-radius:5px}.time{font-size:10px;opacity:.65;margin-top:3px}.bubble audio{display:block;max-width:100%;width:240px;margin-top:6px}.composer{display:flex;align-items:end;gap:9px;padding:13px 16px;border-top:1px solid var(--line)}.composer textarea{flex:1;min-height:45px;max-height:130px;resize:vertical;border:1px solid var(--line);border-radius:15px;background:#fafafa;padding:12px;outline:none}.composer textarea:focus{border-color:#aaa}.status{padding:0 17px 9px;color:var(--muted);font-size:12px;min-height:18px}.overlay{position:fixed;inset:0;background:#0008;display:grid;place-items:center;padding:18px;z-index:20}.modal{background:#fff;border-radius:23px;padding:25px;width:min(430px,100%);box-shadow:0 20px 70px #0002}.modal h2{font-size:24px;margin:0 0 7px}.modal p{color:var(--muted);line-height:1.9}.modal form{display:grid;gap:12px}.tabs{display:flex;gap:8px;margin:16px 0}.tabs button{flex:1}.error{color:var(--danger);font-size:12px;min-height:18px}.hidden{display:none!important}.mobile-back{display:none}.callbox{position:fixed;inset:18px 18px auto 18px;margin:auto;max-width:760px;background:#fff;border:1px solid var(--line);border-radius:20px;box-shadow:0 15px 60px #0002;padding:15px;z-index:15}.callbox video{width:100%;max-height:42vh;background:#111;border-radius:14px;object-fit:contain}.callbox{padding:18px;background:rgba(255,255,255,.96);backdrop-filter:blur(18px);border-color:#e5e7eb;box-shadow:0 24px 80px #1113}.callrow .btn{min-height:42px}.end-call{width:52px;height:52px;border:0;border-radius:50%;background:linear-gradient(135deg,#ff4d67,#d7193f);color:white;font-size:23px;box-shadow:0 8px 22px #d7193f45}.share-preview{min-height:110px;border-radius:16px;background:linear-gradient(135deg,#171923,#292f40);color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:18px;text-align:center}.share-preview .callhint{color:#d7d9e2}.share-icon{font-size:30px}.group-label{padding-top:12px;border-top:1px solid var(--line);margin:0 12px}.group-results{flex:0 0 auto;max-height:180px;min-height:55px}.group-results .empty{padding:10px}.group-results .person{padding:9px 12px}.group-modal{max-height:88dvh;overflow:auto}.group-picker{max-height:190px;overflow:auto;border:1px solid var(--line);border-radius:14px;padding:5px}.group-picker .person{padding:9px}.selected-members{display:flex;flex-wrap:wrap;gap:7px}.member-chip{background:#f0f0f0;border-radius:999px;padding:6px 10px;display:flex;gap:7px;align-items:center}.member-chip button{border:0;background:transparent;font-size:16px}.callbox{z-index:30}.callbox video:not(.hidden){display:block}.callbox #remoteVideo{min-height:180px}.callbox .end-call{width:52px;height:52px}.callbox .calltitle{font-size:17px}.callbox .callhint{line-height:1.7}.callrow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-top:10px}.callrow .spacer{flex:1}.green{background:var(--green)}.red{background:#b42318}.callhint{font-size:12px;color:var(--muted);line-height:1.8}.calltitle{font-weight:700}.screen-tag{font-size:11px;background:#eee;padding:5px 8px;border-radius:9px}
@media(max-width:760px){.app{padding:0;display:block;height:100dvh}.panel{height:100dvh;border:0;border-radius:0}.sidebar{height:100dvh}.chat{height:100dvh}.app.chat-open .sidebar{display:none}.app:not(.chat-open) .chat{display:none}.mobile-back{display:grid}.chat-head{padding:11px}.messages{padding:15px}.bubble{max-width:89%}.composer{padding:10px}.actions .optional{display:inline-flex}.actions .optional.hidden{display:none!important}.actions .btn.icon{width:36px;height:36px}.callbox{inset:8px 8px auto}}
</style></head><body>
<div class="app" id="app"><aside class="panel sidebar"><header class="brand"><div class="logo">T</div><div><h1>Tchat</h1><p>ارتباط، بدون پیچیدگی</p></div></header><div class="userbar"><div class="avatar" id="myAvatar">T</div><div class="userinfo"><div class="username" id="myName">مهمان</div><div class="sub" id="mySub">برای شروع وارد شو</div></div><button class="btn light" id="accountBtn">ورود</button></div><div class="search"><input id="search" placeholder="جست‌وجوی نام کاربری..." autocomplete="off"><button class="btn light" id="createGroupBtn" style="width:100%;margin-top:9px">＋ ساخت گروه</button></div><div class="section-label" id="listTitle">گفت‌وگوهای اخیر</div><div class="results" id="results"><div class="empty">برای شروع یک نفر را جست‌وجو کن.</div></div><div class="section-label group-label">گروه‌ها</div><div class="results group-results" id="groupResults"><div class="empty">هنوز گروهی نداری.</div></div><div class="notice">برای تماس و اشتراک صفحه، اجازهٔ مرورگر لازم است.</div></aside>
<main class="panel chat"><header class="chat-head"><button class="btn light icon mobile-back" id="backBtn">→</button><div class="avatar" id="chatAvatar">T</div><div class="userinfo"><div class="username" id="chatName">به Tchat خوش آمدی</div><div class="sub" id="chatSub">یک کاربر را انتخاب کن</div></div><div class="actions"><button class="btn light optional hidden" id="addGroupMemberBtn" title="افزودن عضو">＋ عضو</button><button class="btn light optional hidden" id="leaveGroupBtn" title="خروج از گروه">🚪 خروج از گروه</button><button class="btn light icon optional" id="audioCall" title="تماس صوتی">☎</button><button class="btn light icon optional" id="screenCall" title="اشتراک‌گذاری صفحه">▤</button></div></header><div class="messages" id="messages"><div class="empty">یک گفت‌وگو را انتخاب کن تا شروع کنیم.</div></div><div class="status" id="status"></div><form class="composer" id="sendForm"><button class="btn light icon" type="button" id="recordBtn" title="ضبط پیام صوتی">🎙</button><textarea id="messageInput" maxlength="4000" rows="1" placeholder="پیامت را بنویس..." disabled></textarea><button class="btn icon" id="sendBtn" type="submit" disabled>➤</button></form></main></div>
<div class="overlay" id="authOverlay"><div class="modal"><h2>به Tchat خوش آمدی</h2><p>برای پیدا کردن دوستانت وارد شو یا حساب بساز.</p><div class="tabs"><button class="btn" id="loginTab">ورود</button><button class="btn light" id="registerTab">ثبت‌نام</button></div><form id="authForm"><input class="field" id="authUsername" placeholder="نام کاربری انگلیسی" minlength="3" maxlength="24" autocomplete="username" required><input class="field" id="authPassword" type="password" placeholder="رمز عبور (حداقل ۸ کاراکتر)" minlength="8" maxlength="200" autocomplete="current-password" required><div class="error" id="authError"></div><button class="btn" id="authSubmit">ورود به حساب</button></form><p style="font-size:11px">نام کاربری شما برای سایر کاربران قابل جست‌وجو خواهد بود.</p></div></div>
<div class="overlay hidden" id="incomingOverlay"><div class="modal"><h2>تماس ورودی</h2><p id="incomingText">کاربری با شما تماس می‌گیرد.</p><div class="callrow"><button class="btn green" id="acceptCall">پاسخ دادن</button><button class="btn red" id="rejectCall">رد تماس</button></div></div></div>
<div class="overlay hidden" id="groupOverlay"><div class="modal group-modal"><h2 id="groupModalTitle">ساخت گروه</h2><p>نام گروه و اعضا را انتخاب کن. کاربران باید قبلاً حساب ساخته باشند.</p><form id="groupForm"><input class="field" id="groupNameInput" maxlength="60" placeholder="نام گروه" required><input class="field" id="groupMemberSearch" maxlength="24" placeholder="جست‌وجوی نام کاربری برای افزودن"><div id="groupSearchResults" class="group-picker"><div class="empty">برای جست‌وجوی اعضا نام کاربری را بنویس.</div></div><div id="groupSelected" class="selected-members"></div><div class="error" id="groupError"></div><div class="callrow"><button class="btn" id="saveGroupBtn" type="submit">ساخت گروه</button><button class="btn light" id="cancelGroupBtn" type="button">لغو</button></div></form></div></div>
<div class="callbox hidden" id="callbox"><div class="callrow"><div><div class="calltitle" id="callTitle">تماس</div><div class="callhint" id="callHint">در حال اتصال...</div></div><span class="spacer"></span><span class="screen-tag hidden" id="screenTag">اشتراک صفحه</span><button class="btn light" id="muteBtn">بی‌صدا</button><button class="end-call" id="endCall" title="پایان تماس" aria-label="پایان تماس">📞</button></div><video id="remoteVideo" autoplay playsinline class="hidden"></video><audio id="remoteAudio" autoplay playsinline></audio><div class="share-preview" id="sharePreview"><div class="share-icon">🖥️</div><strong>اشتراک‌گذاری صفحه</strong><div class="callhint" id="localHint">صفحهٔ اشتراک‌گذاری‌شده هنگام اتصال اینجا نمایش داده می‌شود.</div></div><video id="localVideo" autoplay muted playsinline class="hidden"></video></div>
<script>
'use strict';
const $=id=>document.getElementById(id);let csrf='',me=null,current=null,lastId=0,authMode='login',recorder=null,chunks=[],pollTimer=null,searchTimer=null,busy=false,incomingTimer=null;
let pc=null,activeCallId=null,callRole=null,callKind=null,callPollTimer=null,mediaStream=null,displayStream=null,remoteVideoStream=null,remoteAudioStream=null,muted=false,handledIncoming=0,remoteDescriptionSet=false;let selectedGroupMembers=new Map(),groupSearchTimer=null,groupModalMode='create';
const iceServers=[{urls:'stun:stun.l.google.com:19302'},{urls:'stun:stun1.l.google.com:19302'}];
async function api(action,data={},method='POST'){const opt={method,headers:{'X-CSRF-Token':csrf}};if(method==='POST'){if(data instanceof FormData)opt.body=data;else{opt.headers['Content-Type']='application/json';opt.body=JSON.stringify(data)}}const url='?'+new URLSearchParams({action,...(method==='GET'?data:{})});const r=await fetch(url,opt);const j=await r.json().catch(()=>({ok:false,error:'پاسخ سرور نامعتبر است.'}));if(!r.ok||!j.ok)throw new Error(j.error||'درخواست ناموفق بود.');return j}
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}function letter(s){return(s||'T').charAt(0).toUpperCase()}function status(s){$('status').textContent=s||''}
function showAuth(mode='login'){authMode=mode;$('authOverlay').classList.remove('hidden');$('loginTab').className=mode==='login'?'btn':'btn light';$('registerTab').className=mode==='register'?'btn':'btn light';$('authSubmit').textContent=mode==='login'?'ورود به حساب':'ساخت حساب';$('authError').textContent='';$('authPassword').autocomplete=mode==='login'?'current-password':'new-password'}function closeAuth(){$('authOverlay').classList.add('hidden')}
async function loadMe(){const r=await api('me',{},'GET');csrf=r.csrf||csrf;me=r.user;if(me){$('myName').textContent=me.username;$('myAvatar').textContent=letter(me.username);$('mySub').textContent='@'+me.username;$('accountBtn').textContent='خروج';closeAuth();await loadChats()}else{me=null;$('myName').textContent='مهمان';$('mySub').textContent='برای شروع وارد شو';$('accountBtn').textContent='ورود';showAuth()}}
function bindPeople(box){box.querySelectorAll('.person').forEach(el=>el.addEventListener('click',()=>el.dataset.type==='group'?openGroup(Number(String(el.dataset.id).slice(1)),el.dataset.name):openChat(Number(el.dataset.id),el.dataset.name)))}
function renderPeople(users,title){$('listTitle').textContent=title;const box=$('results');if(!users.length){box.innerHTML='<div class="empty">گفت‌وگویی پیدا نشد.</div>';return}box.innerHTML=users.map(u=>`<div class="person ${current&&current.id==u.id?'active':''}" data-id="${esc(u.id)}" data-type="user" data-name="${esc(u.username)}"><div class="avatar">${esc(letter(u.username))}</div><div class="userinfo"><div class="username">${esc(u.username)}</div><div class="sub">@${esc(u.username)}</div></div></div>`).join('');bindPeople(box)}
function renderGroups(groups){const box=$('groupResults');if(!groups.length){box.innerHTML='<div class="empty">هنوز گروهی نداری. از «ساخت گروه» شروع کن.</div>';return}box.innerHTML=groups.map(g=>`<div class="person ${current&&current.type==='group'&&Number(current.id)===Number(g.id)?'active':''}" data-id="g${esc(g.id)}" data-type="group" data-name="${esc(g.name)}"><div class="avatar">👥</div><div class="userinfo"><div class="username">${esc(g.name)}</div><div class="sub">${Number(g.member_count||1)} عضو</div></div></div>`).join('');bindPeople(box)}
async function loadChats(){if(!me)return;const results=await Promise.allSettled([api('chats',{}),api('groups',{})]);if(results[0].status==='fulfilled')renderPeople((results[0].value.chats||[]),'گفت‌وگوهای اخیر');else status('بارگذاری گفت‌وگوها: '+results[0].reason.message);if(results[1].status==='fulfilled')renderGroups(results[1].value.groups||[]);else{ $('groupResults').innerHTML='<div class="empty">بارگذاری گروه‌ها ناموفق بود. '+esc(results[1].reason.message)+'</div>';status('بارگذاری گروه‌ها: '+results[1].reason.message)}}
function openGroupModal(mode='create'){
 if(!me){showAuth();return} groupModalMode=mode;selectedGroupMembers=new Map();$('groupNameInput').value=mode==='create'?'':(current?.username||'');$('groupNameInput').classList.toggle('hidden',mode==='add');$('groupNameInput').required=mode==='create';$('groupModalTitle').textContent=mode==='create'?'ساخت گروه جدید':'افزودن عضو به گروه';$('saveGroupBtn').textContent=mode==='create'?'ساخت گروه':'افزودن اعضا';$('groupMemberSearch').value='';$('groupSearchResults').innerHTML='<div class="empty">برای جست‌وجوی اعضا نام کاربری را بنویس.</div>';$('groupSelected').innerHTML='';$('groupError').textContent='';$('groupOverlay').classList.remove('hidden');if(mode==='add')$('groupNameInput').blur();else $('groupNameInput').focus();
}
function renderSelectedMembers(){ $('groupSelected').innerHTML=[...selectedGroupMembers.values()].map(u=>`<span class="member-chip">@${esc(u.username)} <button type="button" data-remove-member="${esc(u.id)}" aria-label="حذف">×</button></span>`).join('');$('groupSelected').querySelectorAll('[data-remove-member]').forEach(b=>b.onclick=()=>{selectedGroupMembers.delete(Number(b.dataset.removeMember));renderSelectedMembers()}) }
$('createGroupBtn').onclick=()=>openGroupModal('create');$('addGroupMemberBtn').onclick=()=>{if(current?.type==='group')openGroupModal('add')};$('leaveGroupBtn').onclick=async()=>{if(!current||current.type!=='group')return;const leaving={...current};if(!confirm('مطمئنی می‌خواهی از گروه «'+leaving.username+'» خارج شوی؟'))return;const btn=$('leaveGroupBtn');btn.disabled=true;try{const r=await api('group-leave',{group:leaving.id});if(pollTimer)clearInterval(pollTimer);current=null;lastId=0;$('addGroupMemberBtn').classList.add('hidden');$('leaveGroupBtn').classList.add('hidden');$('chatName').textContent='گفت‌وگو';$('chatSub').textContent='یک گفت‌وگو را انتخاب کن';$('chatAvatar').textContent='T';$('messages').innerHTML='<div class="empty">از گروه خارج شدی. یک گفت‌وگو را انتخاب کن.</div>';$('messageInput').disabled=$('sendBtn').disabled=true;$('app').classList.remove('chat-open');await loadChats();status(r.message||'از گروه خارج شدی.')}catch(e){status(e.message)}finally{btn.disabled=false}};$('cancelGroupBtn').onclick=()=>$('groupOverlay').classList.add('hidden');$('groupOverlay').addEventListener('click',e=>{if(e.target===$('groupOverlay'))$('groupOverlay').classList.add('hidden')});
$('groupMemberSearch').addEventListener('input',()=>{clearTimeout(groupSearchTimer);const q=$('groupMemberSearch').value.trim();groupSearchTimer=setTimeout(async()=>{if(!q){$('groupSearchResults').innerHTML='<div class="empty">برای جست‌وجوی اعضا نام کاربری را بنویس.</div>';return}try{const r=await api('search',{q});const users=(r.users||[]).filter(u=>!selectedGroupMembers.has(Number(u.id)));$('groupSearchResults').innerHTML=users.length?users.map(u=>`<div class="person" data-member-id="${esc(u.id)}"><div class="avatar">${esc(letter(u.username))}</div><div class="userinfo"><div class="username">${esc(u.username)}</div><div class="sub">@${esc(u.username)}</div></div><button class="btn light" type="button">افزودن</button></div>`).join(''):'<div class="empty">کاربری پیدا نشد یا همه انتخاب شده‌اند.</div>'; $('groupSearchResults').querySelectorAll('[data-member-id]').forEach(el=>el.onclick=()=>{const id=Number(el.dataset.memberId),u=(r.users||[]).find(x=>Number(x.id)===id);if(u){selectedGroupMembers.set(id,u);renderSelectedMembers();el.remove()}})}catch(e){$('groupError').textContent=e.message}},250)});
$('groupForm').addEventListener('submit',async e=>{e.preventDefault();$('groupError').textContent='';const members=[...selectedGroupMembers.keys()].map(Number);try{if(groupModalMode==='create'){const name=$('groupNameInput').value.trim();if(!name)throw new Error('نام گروه را وارد کن.');const r=await api('group-create',{name,members});$('groupOverlay').classList.add('hidden');await loadChats();await openGroup(r.group.id,r.group.name);status(r.not_found?.length?'گروه ساخته شد؛ پیدا نشد: '+r.not_found.join(', '):'گروه ساخته شد و در فهرست گروه‌ها ذخیره شد.')}else{if(!current||current.type!=='group')throw new Error('ابتدا یک گروه انتخاب کن.');if(!members.length)throw new Error('حداقل یک عضو را انتخاب کن.');const r=await api('group-add',{group:current.id,members});$('groupOverlay').classList.add('hidden');let msg=[];if(r.added?.length)msg.push('اضافه شد: '+r.added.join(', '));if(r.already?.length)msg.push('از قبل عضو بودند: '+r.already.join(', '));if(r.not_found?.length)msg.push('پیدا نشد: '+r.not_found.join(', '));status(msg.join(' | ')||'اعضا به‌روزرسانی شدند.');await loadChats()}}catch(err){$('groupError').textContent=err.message}});

$('search').addEventListener('input',()=>{clearTimeout(searchTimer);const q=$('search').value.trim();searchTimer=setTimeout(async()=>{if(!me){showAuth();return}if(!q){await loadChats();return}try{renderPeople((await api('search',{q})).users,'نتایج جست‌وجو')}catch(e){status(e.message)}},250)});
async function openChat(id,username){if(!me){showAuth();return}current={id,username,type:'user'};$('addGroupMemberBtn').classList.add('hidden');$('leaveGroupBtn').classList.add('hidden');try{await api('chat-open',{with:Number(id)});await loadChats()}catch(e){}lastId=0;$('chatName').textContent=username;$('chatSub').textContent='@'+username;$('chatAvatar').textContent=letter(username);$('messageInput').disabled=false;$('sendBtn').disabled=false;$('app').classList.add('chat-open');$('messages').innerHTML='';await loadMessages(true);if(pollTimer)clearInterval(pollTimer);pollTimer=setInterval(()=>loadMessages(false),1600)}
async function openGroup(id,name){if(!me){showAuth();return}current={id:Number(id),username:name,type:'group'};$('addGroupMemberBtn').classList.remove('hidden');$('leaveGroupBtn').classList.remove('hidden');lastId=0;$('chatName').textContent=name;$('chatSub').textContent='گروه';$('chatAvatar').textContent='👥';$('messageInput').disabled=false;$('sendBtn').disabled=false;$('app').classList.add('chat-open');$('messages').innerHTML='';await loadMessages(true);if(pollTimer)clearInterval(pollTimer);pollTimer=setInterval(()=>loadMessages(false),1800)}
function drawMessage(m){const mine=Number(m.sender_id)===Number(me.id),node=document.createElement('div');node.className='bubble '+(mine?'mine':'theirs');let body=m.body?esc(m.body):'';if(current.type==='group'&&!mine)body='<b>'+esc(m.username||'عضو')+'</b><br>'+body;if(m.audio_url)body+='<audio controls preload="none" src="'+esc(m.audio_url)+'"></audio>';node.innerHTML=body+'<div class="time">'+esc(m.created_at||'')+'</div>';$('messages').appendChild(node)}
async function loadMessages(initial){if(!current||busy)return;busy=true;try{const r=current.type==='group'?await api('group-messages',{group:current.id,after:initial?0:lastId},'GET'):await api('messages',{with:current.id,after:initial?0:lastId},'GET');if(initial)$('messages').innerHTML='';(r.messages||[]).forEach(m=>{drawMessage(m);lastId=Math.max(lastId,Number(m.id))});if(initial&&!(r.messages||[]).length)$('messages').innerHTML='<div class="empty">اولین پیام این گفت‌وگو را بفرست.</div>';else if((r.messages||[]).length)$('messages').scrollTop=$('messages').scrollHeight;if(initial)loadChats()}catch(e){status(e.message)}finally{busy=false}}
$('sendForm').addEventListener('submit',async e=>{e.preventDefault();const body=$('messageInput').value.trim();if(!current||!body)return;$('sendBtn').disabled=true;try{await api(current.type==='group'?'group-send':'send',current.type==='group'?{group:current.id,body}:{to:current.id,body});$('messageInput').value='';await loadMessages(false);await loadChats()}catch(e){status(e.message)}finally{$('sendBtn').disabled=!current}});
$('accountBtn').onclick=async()=>{if(!me)return showAuth();if(!confirm('از حساب خارج می‌شوی؟'))return;try{await api('logout',{});me=current=null;if(pollTimer)clearInterval(pollTimer);$('messages').innerHTML='<div class="empty">برای شروع وارد حساب شو.</div>';$('messageInput').disabled=$('sendBtn').disabled=true;await loadMe()}catch(e){status(e.message)}};
$('loginTab').onclick=()=>showAuth('login');$('registerTab').onclick=()=>showAuth('register');$('authForm').addEventListener('submit',async e=>{e.preventDefault();$('authError').textContent='';try{const r=await api(authMode==='login'?'login':'register',{username:$('authUsername').value.trim(),password:$('authPassword').value});csrf=r.csrf||csrf;$('authPassword').value='';await loadMe()}catch(err){$('authError').textContent=err.message}});$('backBtn').onclick=()=>$('app').classList.remove('chat-open');
$('recordBtn').onclick=async()=>{if(!current){status('ابتدا یک گفت‌وگو انتخاب کن.');return}if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){status('ضبط صدا پشتیبانی نمی‌شود؛ HTTPS را بررسی کن.');return}if(recorder&&recorder.state==='recording'){recorder.stop();$('recordBtn').textContent='🎙';status('در حال ارسال پیام صوتی...');return}try{const stream=await navigator.mediaDevices.getUserMedia({audio:true});chunks=[];let mime=MediaRecorder.isTypeSupported('audio/webm')?'audio/webm':'';recorder=new MediaRecorder(stream,mime?{mimeType:mime}:{});recorder.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};recorder.onstop=async()=>{stream.getTracks().forEach(t=>t.stop());try{const blob=new Blob(chunks,{type:recorder.mimeType||'audio/webm'});if(blob.size>5242880)throw new Error('حداکثر حجم پیام ۵ مگابایت است.');const f=new FormData();f.append('to',String(current.id));f.append('audio',blob,'voice.webm');if(current.type==='group')throw new Error('ارسال پیام صوتی در گروه هنوز فعال نیست.');await api('voice',f);status('پیام صوتی ارسال شد.');await loadMessages(false);await loadChats()}catch(e){status(e.message)}};recorder.start();$('recordBtn').textContent='■';status('در حال ضبط؛ برای پایان دوباره بزن.')}catch(e){status('اجازه میکروفون داده نشد.')}};
function waitIce(pcRef){return new Promise(resolve=>{if(pcRef.iceGatheringState==='complete')return resolve();const timer=setTimeout(resolve,7000);function check(){if(pcRef.iceGatheringState==='complete'){clearTimeout(timer);pcRef.removeEventListener('icegatheringstatechange',check);resolve()}}pcRef.addEventListener('icegatheringstatechange',check)})}
function createPC(){
 pc=new RTCPeerConnection({iceServers});
 remoteVideoStream=new MediaStream();remoteAudioStream=new MediaStream();
 $('remoteVideo').srcObject=remoteVideoStream;$('remoteAudio').srcObject=remoteAudioStream;
 pc.ontrack=e=>{
  const track=e.track;
  if(track.kind==='video'){
   if(!remoteVideoStream.getTracks().some(t=>t.id===track.id))remoteVideoStream.addTrack(track);
   $('remoteVideo').classList.remove('hidden');$('sharePreview').classList.add('hidden');$('remoteVideo').play().catch(()=>{});
   track.addEventListener('ended',()=>{if(!remoteVideoStream.getVideoTracks().some(t=>t.readyState==='live')){$('remoteVideo').classList.add('hidden');if(callKind==='screen')$('sharePreview').classList.remove('hidden')}});
  }else if(track.kind==='audio'){
   if(!remoteAudioStream.getTracks().some(t=>t.id===track.id))remoteAudioStream.addTrack(track);
   $('remoteAudio').play().catch(()=>{$('callHint').textContent='برای پخش صدا، یک‌بار روی صفحه ضربه بزن.'});
  }
 };
 pc.onconnectionstatechange=()=>{if(!pc)return;if(pc.connectionState==='connected')$('callHint').textContent='ارتباط برقرار شد.';else if(pc.connectionState==='connecting')$('callHint').textContent='در حال برقراری ارتباط...';else if(pc.connectionState==='failed')$('callHint').textContent='اتصال ناموفق است؛ ممکن است شبکه به سرور TURN نیاز داشته باشد.';else if(pc.connectionState==='disconnected')$('callHint').textContent='اتصال ناپایدار است؛ در حال تلاش برای بازیابی...'};
 return pc;
}
async function getMicrophoneOptional(){
 try{return await navigator.mediaDevices.getUserMedia({audio:true,video:false})}
 catch(e){status('اجازهٔ میکروفون داده نشد؛ می‌توانی بدون ارسال صدای خودت ادامه بدهی.');return null}
}
function attachAudioOrReceiveOnly(stream,createReceiveOnlyLine=false){
 if(stream&&stream.getAudioTracks().length){stream.getAudioTracks().forEach(t=>pc.addTrack(t,stream));}
 else if(createReceiveOnlyLine){try{pc.addTransceiver('audio',{direction:'recvonly'})}catch(e){}}
}
async function startCall(kind){
 if(activeCallId){status('تماسی در حال اجراست؛ ابتدا تماس فعلی را پایان بده.');return}
 if(current?.type==='group'){status('تماس گروهی واقعی به سرور رسانه‌ای SFU نیاز دارد.');return}
 if(!current){status('ابتدا یک کاربر انتخاب کن.');return}
 if(!window.RTCPeerConnection){status('این مرورگر از WebRTC پشتیبانی نمی‌کند.');return}
 if(kind==='screen'&&!window.isSecureContext){status('برای اشتراک صفحه باید سایت با HTTPS باز شود؛ در HTTP مرورگر اجازه نمی‌دهد.');return}
 let createdCallId=null;
 try{
  callKind=kind;callRole='caller';mediaStream=null;displayStream=null;
  if(!navigator.mediaDevices){throw new Error('قابلیت‌های رسانه‌ای در این مرورگر فعال نیستند؛ سایت را با HTTPS در مرورگر سازگار باز کن.')}
  if(kind==='screen'){
   if(!navigator.mediaDevices.getDisplayMedia)throw new Error('این مرورگر اشتراک صفحه را پشتیبانی نمی‌کند. در مرورگر دسکتاپ سازگار و روی HTTPS امتحان کن.');
   // Must be invoked directly from the button gesture. Request video only for better browser compatibility.
   displayStream=await navigator.mediaDevices.getDisplayMedia({video:true,audio:false});
   if(!displayStream.getVideoTracks().length)throw new Error('مرورگر هیچ تصویر صفحه‌ای برنگرداند.');
   mediaStream=await getMicrophoneOptional();
  }else{
   mediaStream=await navigator.mediaDevices.getUserMedia({audio:true,video:false});$('screenTag').classList.add('hidden');$('sharePreview').classList.add('hidden');
  }
  createPC();
  if(displayStream){
   displayStream.getVideoTracks().forEach(t=>pc.addTrack(t,displayStream));
   $('localVideo').srcObject=displayStream;$('localVideo').classList.remove('hidden');$('sharePreview').classList.add('hidden');
   $('screenTag').classList.remove('hidden');$('localHint').textContent='صفحهٔ انتخاب‌شده در حال آماده‌سازی است.';
   const sharedTrack=displayStream.getVideoTracks()[0];
   sharedTrack.addEventListener('ended',()=>{
    $('screenTag').classList.add('hidden');$('localVideo').classList.add('hidden');$('localVideo').srcObject=null;
    $('callHint').textContent='اشتراک صفحه متوقف شد؛ تماس صوتی ادامه دارد.';
    if(pc){const sender=pc.getSenders().find(x=>x.track===sharedTrack);if(sender)try{pc.removeTrack(sender)}catch(e){}}
   });
  }
  attachAudioOrReceiveOnly(mediaStream,true);
  const offer=await pc.createOffer();await pc.setLocalDescription(offer);await waitIce(pc);
  const r=await api('call-start',{to:current.id,kind,offer:JSON.stringify(pc.localDescription)});activeCallId=Number(r.call_id);createdCallId=activeCallId;
  $('callTitle').textContent=kind==='screen'?'اشتراک‌گذاری صفحه':'تماس صوتی';$('callHint').textContent='در انتظار پاسخ...';$('callbox').classList.remove('hidden');beginCallPolling();
 }catch(e){
  status((e&&e.name==='NotAllowedError')?'اجازهٔ اشتراک صفحه رد شد یا پنجرهٔ انتخاب لغو شد.':(e.message||'شروع تماس ناموفق بود.'));
  if(createdCallId){try{await api('call-end',{call_id:createdCallId})}catch(_){}}
  cleanupCall();
 }
}
async function beginCallPolling(){if(callPollTimer)clearInterval(callPollTimer);callPollTimer=setInterval(async()=>{if(!activeCallId)return;try{const r=await api('call-poll',{id:activeCallId},'GET'),c=r.call;if(c.status==='ended'||c.status==='rejected'){status(c.status==='rejected'?'تماس رد شد.':'تماس پایان یافت.');cleanupCall();return}if(callRole==='caller'&&c.answer&&!pc.currentRemoteDescription){await pc.setRemoteDescription(JSON.parse(c.answer));$('callHint').textContent='پاسخ دریافت شد؛ در حال اتصال...'}}catch(e){status(e.message);cleanupCall()}},1300)}
async function checkIncoming(){if(!me||activeCallId)return;try{const r=await api('call-incoming',{},'GET'),c=r.call;if(c&&Number(c.id)!==handledIncoming){handledIncoming=Number(c.id);$('incomingText').textContent='@'+c.username+' با شما '+(c.call_type==='screen'?'اشتراک صفحه':c.call_type==='video'?'تماس تصویری':'تماس صوتی')+' می‌گیرد.';$('incomingOverlay').classList.remove('hidden');window.pendingCall=c}}catch(e){}}
$('acceptCall').onclick=async()=>{
 const c=window.pendingCall;if(!c)return;
 try{
  $('incomingOverlay').classList.add('hidden');activeCallId=Number(c.id);callRole='callee';callKind=c.call_type;createPC();
  const offer=JSON.parse(c.offer_sdp);await pc.setRemoteDescription(offer);
  mediaStream=await getMicrophoneOptional();attachAudioOrReceiveOnly(mediaStream,false);
  const answer=await pc.createAnswer();await pc.setLocalDescription(answer);await waitIce(pc);
  await api('call-answer',{call_id:activeCallId,answer:JSON.stringify(pc.localDescription)});
  $('callTitle').textContent=c.call_type==='screen'?'اشتراک‌گذاری صفحه':'تماس صوتی';$('callHint').textContent='در حال اتصال...';$('screenTag').classList.toggle('hidden',c.call_type!=='screen');$('sharePreview').classList.toggle('hidden',c.call_type!=='screen');$('localHint').textContent='در حال دریافت صفحهٔ اشتراک‌گذاری‌شده...';$('callbox').classList.remove('hidden');beginCallPolling();window.pendingCall=null;
 }catch(e){status('پاسخ به تماس ناموفق بود: '+e.message);if(activeCallId){try{await api('call-end',{call_id:activeCallId})}catch(_){}}cleanupCall()}
};
$('rejectCall').onclick=async()=>{const c=window.pendingCall;$('incomingOverlay').classList.add('hidden');if(c)try{await api('call-reject',{call_id:Number(c.id)})}catch(e){}window.pendingCall=null};
async function endCall(){if(activeCallId)try{await api('call-end',{call_id:activeCallId})}catch(e){}cleanupCall()}
function cleanupCall(){
 if(callPollTimer)clearInterval(callPollTimer);callPollTimer=null;
 if(pc){pc.ontrack=null;pc.close()}pc=null;
 if(mediaStream)mediaStream.getTracks().forEach(t=>t.stop());
 if(displayStream&&displayStream!==mediaStream)displayStream.getTracks().forEach(t=>t.stop());
 mediaStream=displayStream=null;remoteVideoStream=remoteAudioStream=null;
 $('remoteVideo').pause();$('remoteVideo').srcObject=null;$('remoteVideo').classList.add('hidden');
 $('remoteAudio').pause();$('remoteAudio').srcObject=null;
 $('sharePreview').classList.remove('hidden');$('localHint').textContent='صفحهٔ اشتراک‌گذاری‌شده هنگام اتصال اینجا نمایش داده می‌شود.';
 $('localVideo').pause();$('localVideo').srcObject=null;$('localVideo').classList.add('hidden');
 $('callbox').classList.add('hidden');$('incomingOverlay').classList.add('hidden');$('screenTag').classList.add('hidden');
 activeCallId=null;callRole=null;callKind=null;muted=false;$('muteBtn').textContent='بی‌صدا';window.pendingCall=null;
}
$('endCall').onclick=endCall;$('audioCall').onclick=()=>startCall('audio');$('screenCall').onclick=()=>startCall('screen');$('muteBtn').onclick=()=>{const tracks=[...(mediaStream?.getAudioTracks()||[])];if(!tracks.length){status('در این تماس میکروفون فعالی در دسترس نیست.');return}muted=!muted;tracks.forEach(t=>t.enabled=!muted);$('muteBtn').textContent=muted?'🔊 روشن‌کردن میکروفون':'🎙 بی‌صدا';$('callHint').textContent=muted?'میکروفون خاموش است.':'میکروفون روشن است.'};
loadMe().catch(e=>{status(e.message);showAuth()});incomingTimer=setInterval(checkIncoming,2200);
</script></body></html>
