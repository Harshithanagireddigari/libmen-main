<?php
/*
 * Libmen's only server-side application file.
 * The HTML/CSS/JavaScript frontend calls this JSON API; PHP is used solely
 * for the MySQL connection and database operations.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function respond($data, int $status = 200): void { http_response_code($status); echo json_encode($data); exit; }
function fail(string $message, int $status = 400): void { respond(['ok' => false, 'error' => $message], $status); }
function body(): array { $raw = file_get_contents('php://input'); return $raw ? (json_decode($raw, true) ?: []) : $_POST; }
function value(array $data, string $key): string { return trim((string)($data[$key] ?? '')); }

try {
    $cfgFile = __DIR__ . '/config/database.php';
    if (!is_file($cfgFile)) fail('Database is not configured. Copy config/database.example.php to config/database.php and enter your MySQL credentials.', 503);
    $cfg = require $cfgFile;
    $pdo = new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $cfg['host'], $cfg['port'] ?? '3306', $cfg['name']), $cfg['user'], $cfg['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
} catch (Throwable $e) { fail('Database connection failed: ' . $e->getMessage(), 503); }

$action = $_GET['action'] ?? '';
$data = body();
$today = date('Y-m-d');

try {
    if ($action === 'dashboard') {
        $stats = $pdo->query("SELECT (SELECT COUNT(*) FROM books) books,(SELECT COALESCE(SUM(copies),0) FROM books) copies,(SELECT COUNT(*) FROM members) members,(SELECT COUNT(*) FROM loans WHERE status='issued') issued,(SELECT COUNT(*) FROM loans WHERE status='issued' AND due_date<CURDATE()) overdue")->fetch();
        $stats['available'] = max(0, (int)$stats['copies'] - (int)$stats['issued']);
        $recent = $pdo->query("SELECT l.id,l.status,l.issue_date,l.due_date,l.return_date,b.title,m.name member_name FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id ORDER BY l.id DESC LIMIT 7")->fetchAll();
        respond(['ok'=>true,'stats'=>$stats,'recent'=>$recent]);
    }
    if ($action === 'books') {
        $q = '%' . value($data, 'q') . '%'; $category = value($data, 'category');
        $sql = "SELECT b.*, b.copies-COUNT(CASE WHEN l.status='issued' THEN 1 END) available FROM books b LEFT JOIN loans l ON l.book_id=b.id WHERE (b.title LIKE ? OR b.author LIKE ? OR COALESCE(b.isbn,'') LIKE ? OR b.category LIKE ?)";
        $args = [$q,$q,$q,$q]; if ($category !== '') { $sql .= ' AND b.category=?'; $args[]=$category; }
        $sql .= ' GROUP BY b.id ORDER BY b.title'; $st=$pdo->prepare($sql); $st->execute($args);
        $categories=$pdo->query("SELECT DISTINCT category FROM books WHERE category<>'' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
        respond(['ok'=>true,'books'=>$st->fetchAll(),'categories'=>$categories]);
    }
    if ($action === 'members') {
        $q='%' . value($data,'q') . '%'; $status=value($data,'status');
        $sql="SELECT m.*,COUNT(CASE WHEN l.status='issued' THEN 1 END) open_loans FROM members m LEFT JOIN loans l ON l.member_id=m.id WHERE (m.name LIKE ? OR m.member_code LIKE ? OR m.email LIKE ? OR COALESCE(m.phone,'') LIKE ?)"; $args=[$q,$q,$q,$q];
        if($status!==''){ $sql.=' AND m.status=?'; $args[]=$status; } $sql.=' GROUP BY m.id ORDER BY m.name'; $st=$pdo->prepare($sql); $st->execute($args); respond(['ok'=>true,'members'=>$st->fetchAll()]);
    }
    if ($action === 'loan-options') {
        $books=$pdo->query("SELECT b.id,b.title,b.author,(b.copies-COUNT(CASE WHEN l.status='issued' THEN 1 END)) available FROM books b LEFT JOIN loans l ON l.book_id=b.id GROUP BY b.id HAVING available>0 ORDER BY b.title")->fetchAll();
        $members=$pdo->query("SELECT id,name,member_code,max_books FROM members WHERE status='Active' AND valid_till>=CURDATE() ORDER BY name")->fetchAll(); respond(['ok'=>true,'books'=>$books,'members'=>$members]);
    }
    if ($action === 'loans') {
        $status=value($data,'status') ?: 'issued'; $sql="SELECT l.*,b.title,b.author,m.name member_name,m.member_code FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id"; $args=[]; if($status!=='all'){ $sql.=' WHERE l.status=?';$args[]=$status; } $sql.=' ORDER BY CASE WHEN l.status=\'issued\' AND l.due_date<CURDATE() THEN 0 ELSE 1 END,l.due_date DESC,l.id DESC'; $st=$pdo->prepare($sql);$st->execute($args); respond(['ok'=>true,'loans'=>$st->fetchAll()]);
    }
    if ($action === 'report') {
        $days=(int)($data['range'] ?? 30); if(!in_array($days,[7,30,90,365],true))$days=30; $start=date('Y-m-d',strtotime("-$days days"));
        $st=$pdo->prepare("SELECT COUNT(*) issues,(SELECT COUNT(*) FROM loans WHERE status='returned' AND return_date>=?) returns,(SELECT COUNT(*) FROM loans WHERE status='issued' AND due_date<CURDATE()) overdue,(SELECT COUNT(*) FROM members WHERE status='Active') active_members FROM loans WHERE issue_date>=?");$st->execute([$start,$start]);$stats=$st->fetch();
        $st=$pdo->prepare("SELECT b.category,COUNT(*) total FROM loans l JOIN books b ON b.id=l.book_id WHERE l.issue_date>=? GROUP BY b.category ORDER BY total DESC LIMIT 7");$st->execute([$start]);$categories=$st->fetchAll();
        $overdue=$pdo->query("SELECT l.id,l.due_date,b.title,m.name member_name,m.email FROM loans l JOIN books b ON b.id=l.book_id JOIN members m ON m.id=l.member_id WHERE l.status='issued' AND l.due_date<CURDATE() ORDER BY l.due_date")->fetchAll(); respond(['ok'=>true,'stats'=>$stats,'categories'=>$categories,'overdue'=>$overdue]);
    }
    if ($action === 'save-book') {
        $id=(int)($data['id']??0);$title=value($data,'title');$author=value($data,'author');$isbn=value($data,'isbn')?:null;$call=value($data,'call_no')?:null;$category=value($data,'category')?:'Other';$copies=(int)($data['copies']??0);
        if($title===''||$author===''||$copies<1)fail('Title, author, and at least one copy are required.');
        if($id){$st=$pdo->prepare('UPDATE books SET title=?,author=?,isbn=?,call_no=?,category=?,copies=? WHERE id=?');$st->execute([$title,$author,$isbn,$call,$category,$copies,$id]);}else{$st=$pdo->prepare('INSERT INTO books(title,author,isbn,call_no,category,copies) VALUES(?,?,?,?,?,?)');$st->execute([$title,$author,$isbn,$call,$category,$copies]);}$id=$id?:$pdo->lastInsertId();respond(['ok'=>true,'id'=>$id,'message'=>'Book saved.']);
    }
    if ($action === 'delete-book') { $id=(int)($data['id']??0);$st=$pdo->prepare('SELECT COUNT(*) FROM loans WHERE book_id=?');$st->execute([$id]);if((int)$st->fetchColumn())fail('This book has loan history and cannot be deleted.');$pdo->prepare('DELETE FROM books WHERE id=?')->execute([$id]);respond(['ok'=>true,'message'=>'Book deleted.']); }
    if ($action === 'save-member') {
        $id=(int)($data['id']??0);$name=value($data,'name');$email=value($data,'email');$phone=value($data,'phone')?:null;$membership=value($data,'membership')?:'General';$since=value($data,'member_since')?:$today;$valid=value($data,'valid_till')?:date('Y-m-d',strtotime('+1 year'));$max=max(1,(int)($data['max_books']??5));$status=value($data,'status')?:'Active';if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL))fail('A name and valid email are required.');
        if($id){$st=$pdo->prepare('UPDATE members SET name=?,email=?,phone=?,membership=?,member_since=?,valid_till=?,max_books=?,status=? WHERE id=?');$st->execute([$name,$email,$phone,$membership,$since,$valid,$max,$status,$id]);}else{$next=(int)$pdo->query('SELECT COALESCE(MAX(id),0)+1 FROM members')->fetchColumn();$code='LIB-'.str_pad((string)$next,4,'0',STR_PAD_LEFT);$st=$pdo->prepare('INSERT INTO members(member_code,name,email,phone,membership,member_since,valid_till,max_books,status) VALUES(?,?,?,?,?,?,?,?,?)');$st->execute([$code,$name,$email,$phone,$membership,$since,$valid,$max,$status]);$id=$pdo->lastInsertId();}respond(['ok'=>true,'id'=>$id,'message'=>'Member saved.']);
    }
    if ($action === 'delete-member') { $id=(int)($data['id']??0);$st=$pdo->prepare('SELECT COUNT(*) FROM loans WHERE member_id=?');$st->execute([$id]);if((int)$st->fetchColumn())fail('This member has loan history and cannot be deleted.');$pdo->prepare('DELETE FROM members WHERE id=?')->execute([$id]);respond(['ok'=>true,'message'=>'Member deleted.']); }
    if ($action === 'issue') {
        $book=(int)($data['book_id']??0);$member=(int)($data['member_id']??0);$due=value($data,'due_date');if(!$book||!$member||$due==='')fail('Choose a book, member, and due date.');$pdo->beginTransaction();
        $st=$pdo->prepare("SELECT b.copies-COUNT(l.id) available FROM books b LEFT JOIN loans l ON l.book_id=b.id AND l.status='issued' WHERE b.id=? GROUP BY b.id FOR UPDATE");$st->execute([$book]);if((int)$st->fetchColumn()<1)fail('That book is no longer available.');$st=$pdo->prepare("SELECT m.max_books,COUNT(l.id) open_loans FROM members m LEFT JOIN loans l ON l.member_id=m.id AND l.status='issued' WHERE m.id=? AND m.status='Active' AND m.valid_till>=CURDATE() GROUP BY m.id FOR UPDATE");$st->execute([$member]);$m=$st->fetch();if(!$m)fail('This member is inactive or expired.');if((int)$m['open_loans']>=(int)$m['max_books'])fail('This member has reached their book limit.');$pdo->prepare("INSERT INTO loans(book_id,member_id,issue_date,due_date) VALUES(?,?,CURDATE(),?)")->execute([$book,$member,$due]);$pdo->commit();respond(['ok'=>true,'message'=>'Book issued successfully.']);
    }
    if ($action === 'return') { $id=(int)($data['id']??0);$st=$pdo->prepare("UPDATE loans SET status='returned',return_date=CURDATE(),fine=GREATEST(0,DATEDIFF(CURDATE(),due_date))*10 WHERE id=? AND status='issued'");$st->execute([$id]);if(!$st->rowCount())fail('Loan was not found or already returned.');respond(['ok'=>true,'message'=>'Book returned and fine calculated.']); }
    if ($action === 'remind') { $id=(int)($data['id']??0);$pdo->prepare('UPDATE loans SET reminded_at=NOW() WHERE id=?')->execute([$id]);respond(['ok'=>true,'message'=>'Reminder marked as sent.']); }
    fail('Unknown API action.',404);
} catch (PDOException $e) { if($pdo->inTransaction())$pdo->rollBack(); fail($e->getCode()==='23000' ? 'That email or ISBN is already in use.' : 'Database error: '.$e->getMessage(),500); } catch (Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); fail($e->getMessage(),500); }
