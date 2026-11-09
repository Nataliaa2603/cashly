<?php
class AppController {
 private $db; public function __construct(){ $this->db=Database::connection(); }
 public function run(string $page): void {
  require_login(); $u=current_user();
  $allowed=['inicio','graficas','resumen','metas','presupuestos','calendario','consejos','perfil','ayuda','pqrs','contacto','notificaciones','admin','usuarios','estadisticas','articulos','soporte','configuracion'];
  if(!in_array($page,$allowed,true)) $page='inicio';
  if(in_array($page,['admin','usuarios','estadisticas','articulos','soporte','configuracion'],true)) require_admin();
  if(($_GET['export']??'')==='pdf' && in_array($page,['inicio','admin'],true)){ $this->renderPdfReport($page); return; }
  if($_SERVER['REQUEST_METHOD']==='POST'){ verify_csrf(); $this->handlePost($page); }
  $this->ensureNotificationTable();
  $this->generateNotifications((int)$u['id']);
  $data=$this->getData($page); extract($data,EXTR_SKIP); require __DIR__.'/../views/layout.php';
 }
 private function ensureNotificationTable(): void {
  $this->db->exec("CREATE TABLE IF NOT EXISTS notifications (
   id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
   user_id BIGINT UNSIGNED NOT NULL,
   notification_key VARCHAR(190) NOT NULL,
   title VARCHAR(180) NOT NULL,
   message VARCHAR(500) NOT NULL,
   notification_type VARCHAR(30) NOT NULL DEFAULT 'info',
   related_url VARCHAR(255) NOT NULL DEFAULT '?page=inicio',
   created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
   read_at DATETIME NULL,
   UNIQUE KEY uq_notification_user_key(user_id,notification_key),
   INDEX idx_notification_user_read(user_id,read_at),
   CONSTRAINT fk_notification_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
 }
 private function addNotification(int $userId,string $key,string $title,string $message,string $type,string $url): void {
  $q=$this->db->prepare('INSERT IGNORE INTO notifications(user_id,notification_key,title,message,notification_type,related_url) VALUES(?,?,?,?,?,?)');
  $q->execute([$userId,$key,$title,$message,$type,$url]);
 }
 private function generateNotifications(int $userId): void {
  // Presupuestos: alertar una sola vez al cruzar 80%, 90% y 100% del periodo vigente.
  $budgets=$this->all('SELECT id,name,limit_amount,period,category,initial_spent,start_date FROM budgets WHERE user_id=?',[$userId]);
  foreach($budgets as $b){
   $period=$b['period'];
   if($period==='weekly') $start=date('Y-m-d',strtotime('monday this week'));
   elseif($period==='yearly') $start=date('Y-01-01');
   else $start=date('Y-m-01');
   if($start<$b['start_date']) $start=$b['start_date'];
   $q=$this->db->prepare('SELECT COALESCE(SUM(amount),0) FROM budget_expenses WHERE budget_id=? AND user_id=? AND expense_date>=? AND expense_date<=CURDATE()');
   $q->execute([(int)$b['id'],$userId,$start]);
   $spent=(float)$b['initial_spent']+(float)$q->fetchColumn();
   $limit=(float)$b['limit_amount']; if($limit<=0) continue;
   $pct=($spent/$limit)*100;
   foreach([80,90,100] as $threshold){
    if($pct >= $threshold){
     $periodKey=$start;
     $title=$threshold>=100?'Presupuesto excedido':($threshold===90?'¡Estás muy cerca de tu límite!':'Presupuesto cerca del límite');
     $message="Has usado ".number_format($pct,0,',','.')."% del presupuesto ‘".$b['name']."’ (".money($spent)." de ".money($limit)."). Revisa tus gastos para evitar excederte.";
     $this->addNotification($userId,'budget-'.$b['id'].'-'.$periodKey.'-'.$threshold,$title,$message,$threshold>=100?'warning':'budget','?page=presupuestos');
    }
   }
  }
  // Metas: felicitación al 100% y aviso motivador al 90%.
  $goals=$this->all('SELECT g.id,g.name,g.target_amount,g.initial_amount+COALESCE((SELECT SUM(amount) FROM goal_deposits gd WHERE gd.goal_id=g.id AND gd.user_id=g.user_id),0) saved FROM savings_goals g WHERE g.user_id=?',[$userId]);
  foreach($goals as $g){$target=(float)$g['target_amount'];$saved=(float)$g['saved'];if($target<=0)continue;$pct=($saved/$target)*100;
   foreach([90,100] as $threshold){if($pct >= $threshold){$title=$threshold===100?'¡Meta alcanzada! 🎉':'¡Ya casi lo logras! 💙';$message=$threshold===100?'¡Felicidades! Completaste tu meta “'.$g['name'].'” con '.money($saved).'.':'Has alcanzado el '.number_format($pct,0,',','.').'% de tu meta “'.$g['name'].'”. ¡Te falta muy poco!';$this->addNotification($userId,'goal-'.$g['id'].'-'.$threshold,$title,$message,'goal','?page=metas');}}
  }
  // Pagos y suscripciones: avisar tres días antes y el día del vencimiento.
  $events=$this->all("SELECT id,title,event_type,event_date,amount FROM reminders WHERE user_id=? AND event_type IN ('payment','subscription') AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 3 DAY)",[$userId]);
  foreach($events as $ev){$days=(int)floor((strtotime($ev['event_date'])-strtotime(date('Y-m-d')))/86400);if($days>0){$key='payment-'.$ev['id'].'-'.$ev['event_date'].'-before';$title='Próximo pago';$message='Faltan '.$days.' día(s) para “'.$ev['title'].'”'.((float)$ev['amount']>0?' por '.money($ev['amount']):'').'.';}else{$key='payment-'.$ev['id'].'-'.$ev['event_date'].'-today';$title='Hoy tienes un pago';$message='Hoy vence “'.$ev['title'].'”'.((float)$ev['amount']>0?' por '.money($ev['amount']):'').'. Recuerda realizarlo.';}$this->addNotification($userId,$key,$title,$message,'payment','?page=calendario');}
 }
 private function handlePost(string $page): void {
  $u=current_user(); $id=(int)$u['id']; $action=$_POST['action']??'';
  try {
  if($page==='notificaciones' && $action==='mark_notifications_read'){ $this->db->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL')->execute([$id]); flash('Notificaciones marcadas como leídas.'); }
  if($page==='inicio'){
    if($action==='save_movement') { $mid=(int)($_POST['id']??0); $vals=[$_POST['type']??'expense',(float)($_POST['amount']??0),trim($_POST['category']??''),$_POST['payment_method']??'Efectivo',$_POST['movement_date']??date('Y-m-d'),trim($_POST['description']??'')]; if(!in_array($vals[0],['income','expense'])||$vals[1]<=0||$vals[2]==='') throw new Exception('Completa el tipo, un monto mayor que cero y la categoría.'); if($mid){$q=$this->db->prepare('UPDATE movements SET type=?,amount=?,category=?,payment_method=?,movement_date=?,description=? WHERE id=? AND user_id=?');$q->execute(array_merge($vals, [$mid, $id]));}else{$q=$this->db->prepare('INSERT INTO movements(type,amount,category,payment_method,movement_date,description,user_id) VALUES(?,?,?,?,?,?,?)');$q->execute(array_merge($vals, [$id]));} flash('Movimiento guardado correctamente.'); }
    if($action==='delete_movement'){$this->db->prepare('DELETE FROM movements WHERE id=? AND user_id=?')->execute([(int)$_POST['id'],$id]);flash('Movimiento eliminado.');}
   }
   if($page==='metas'){
   
if ($action === 'save_goal') {
    $gid = (int)($_POST['id'] ?? 0);

    $v = [
        trim($_POST['name'] ?? ''),
        (float)($_POST['target_amount'] ?? 0),
        (float)($_POST['initial_amount'] ?? 0),
        ($_POST['deadline'] ?? '') ?: null
    ];

    if ($v[0] === '' || $v[1] <= 0 || $v[2] < 0) {
        throw new Exception('Revisa los datos de la meta.');
    }

    if ($gid) {
        $q = $this->db->prepare(
            'UPDATE savings_goals
             SET name=?, target_amount=?, initial_amount=?, deadline=?
             WHERE id=? AND user_id=?'
        );
        $q->execute(array_merge($v, [$gid, $id]));
    } else {
        $q = $this->db->prepare(
            'INSERT INTO savings_goals
             (name, target_amount, initial_amount, deadline, user_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $q->execute(array_merge($v, [$id]));

        $gid = (int)$this->db->lastInsertId();

    }

    flash('Meta guardada.');
}

    if($action==='deposit'){$gid=(int)$_POST['goal_id'];$amount=(float)$_POST['amount'];if($amount<=0)throw new Exception('El abono debe ser mayor que cero.');$q=$this->db->prepare('SELECT target_amount,initial_amount+COALESCE((SELECT SUM(amount) FROM goal_deposits WHERE goal_id=savings_goals.id),0) saved FROM savings_goals WHERE id=? AND user_id=?');$q->execute([$gid,$id]);$goal=$q->fetch(PDO::FETCH_ASSOC);if(!$goal)throw new Exception('No se encontró esa meta.');if((float)$goal['saved'] >= (float)$goal['target_amount'])throw new Exception('¡Esta meta ya está completada! No puedes registrar más abonos.');$this->db->prepare('INSERT INTO goal_deposits(goal_id,user_id,amount,deposit_date,note) VALUES(?,?,?,?,?)')->execute([$gid,$id,$amount,$_POST['deposit_date']??date('Y-m-d'),trim($_POST['note']??'')]);$newSaved=(float)$goal['saved']+$amount;flash($newSaved >= (float)$goal['target_amount'] ? '🎉 ¡Felicidades! ¡Has completado tu meta de ahorro!':'Abono registrado. ¡Cada paso cuenta!');}
    if($action==='delete_goal'){$this->db->prepare('DELETE FROM savings_goals WHERE id=? AND user_id=?')->execute([(int)$_POST['id'],$id]);flash('Meta eliminada.');}
    if($action==='update_deposit'){$did=(int)($_POST['id']??0);$gid=(int)($_POST['goal_id']??0);$amount=(float)($_POST['amount']??0);if($amount<=0)throw new Exception('El abono debe ser mayor que cero.');$q=$this->db->prepare('SELECT target_amount,initial_amount+COALESCE((SELECT SUM(amount) FROM goal_deposits WHERE goal_id=savings_goals.id AND id<>?),0) saved FROM savings_goals WHERE id=? AND user_id=?');$q->execute([$did,$gid,$id]);$goal=$q->fetch(PDO::FETCH_ASSOC);if(!$goal)throw new Exception('No se encontró esa meta.');if((float)$goal['saved'] >= (float)$goal['target_amount'])throw new Exception('Esta meta ya está completada y no admite más abonos.');$this->db->prepare('UPDATE goal_deposits SET goal_id=?,amount=?,deposit_date=?,note=? WHERE id=? AND user_id=? AND EXISTS(SELECT 1 FROM savings_goals WHERE id=? AND user_id=?)')->execute([$gid,$amount,$_POST['deposit_date']??date('Y-m-d'),trim($_POST['note']??''),$did,$id,$gid,$id]);flash(((float)$goal['saved']+$amount >= (float)$goal['target_amount'])?'🎉 ¡Felicidades! ¡Has completado tu meta de ahorro!':'Abono actualizado.');}
    if($action==='delete_deposit'){$this->db->prepare('DELETE FROM goal_deposits WHERE id=? AND user_id=?')->execute([(int)$_POST['id'],$id]);flash('Abono eliminado.');}
   }
   if($page==='presupuestos'){
    
if ($action === 'save_budget') {
    $bid = (int)($_POST['id'] ?? 0);

    $v = [
        trim($_POST['name'] ?? ''),
        (float)($_POST['limit_amount'] ?? 0),
        $_POST['period'] ?? 'monthly',
        trim($_POST['category'] ?? ''),
        (float)($_POST['initial_spent'] ?? 0),
        $_POST['start_date'] ?? date('Y-m-d')
    ];

    if ($v[0] === '' || $v[1] <= 0 ||
        !in_array($v[2], ['weekly', 'monthly', 'yearly'])) {
        throw new Exception('Revisa el presupuesto.');
    }

    if ($bid) {
        $q = $this->db->prepare(
            'UPDATE budgets SET name=?, limit_amount=?, period=?,
             category=?, initial_spent=?, start_date=?
             WHERE id=? AND user_id=?'
        );
        $q->execute(array_merge($v, [$bid, $id]));
    } else {
        $q = $this->db->prepare(
            'INSERT INTO budgets
             (name, limit_amount, period, category, initial_spent,
              start_date, user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $q->execute(array_merge($v, [$id]));
    }
        flash('Presupuesto guardado.');
}

    if($action==='budget_expense'){$bid=(int)($_POST['budget_id']??0);$amount=(float)($_POST['amount']??0);if($bid<=0||$amount<=0)throw new Exception('Selecciona un presupuesto y escribe un gasto mayor que cero.');$q=$this->db->prepare('INSERT INTO budget_expenses(budget_id,user_id,amount,expense_date,description) SELECT id,user_id,?,?,? FROM budgets WHERE id=? AND user_id=?');$q->execute([$amount,$_POST['expense_date']??date('Y-m-d'),trim($_POST['description']??''),$bid,$id]);if($q->rowCount()!==1)throw new Exception('No se guardó el gasto: el presupuesto no existe o no te pertenece.');flash('Gasto guardado en el presupuesto y disponible en el historial de Inicio.');}
    if($action==='delete_budget'){$this->db->prepare('DELETE FROM budgets WHERE id=? AND user_id=?')->execute([(int)$_POST['id'],$id]);flash('Presupuesto eliminado.');}
    if($action==='update_budget_expense'){$eid=(int)($_POST['id']??0);$bid=(int)($_POST['budget_id']??0);$amount=(float)($_POST['amount']??0);if($amount<=0)throw new Exception('El gasto debe ser mayor que cero.');$this->db->prepare('UPDATE budget_expenses SET budget_id=?,amount=?,expense_date=?,description=? WHERE id=? AND user_id=? AND EXISTS(SELECT 1 FROM budgets WHERE id=? AND user_id=?)')->execute([$bid,$amount,$_POST['expense_date']??date('Y-m-d'),trim($_POST['description']??''),$eid,$id,$bid,$id]);flash('Gasto actualizado.');}
    if($action==='delete_budget_expense'){$this->db->prepare('DELETE FROM budget_expenses WHERE id=? AND user_id=?')->execute([(int)$_POST['id'],$id]);flash('Gasto eliminado.');}
   }
   if($page==='calendario' && $action==='save_event'){$eid=(int)($_POST['id']??0);$v=[trim($_POST['title']??''),$_POST['event_type']??'payment',$_POST['event_date']??date('Y-m-d'),$_POST['event_time']?:null,(float)($_POST['amount']??0),trim($_POST['notes']??''),(int)!empty($_POST['recurring'])];if($v[0]==='')throw new Exception('Escribe el nombre del recordatorio.');if($eid)$this->db->prepare('UPDATE reminders SET title=?,event_type=?,event_date=?,event_time=?,amount=?,notes=?,recurring=? WHERE id=? AND user_id=?')->execute(array_merge($v, [$eid, $id]));else $this->db->prepare('INSERT INTO reminders(title,event_type,event_date,event_time,amount,notes,recurring,user_id) VALUES(?,?,?,?,?,?,?,?)')->execute(array_merge($v, [$id]));flash('Recordatorio guardado.');}
   if($page==='calendario' && $action==='delete_event'){$this->db->prepare('DELETE FROM reminders WHERE id=? AND user_id=?')->execute([(int)$_POST['id'],$id]);flash('Recordatorio eliminado.');}
   if($page==='perfil' && $action==='save_profile'){$name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$phone=trim($_POST['phone']??'');$theme=$_POST['theme']??'blue';if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||!in_array($theme,['light','blue','dark']))throw new Exception('Revisa los datos del perfil.');$avatar=$u['avatar']??null;if(!empty($_FILES['avatar']['name'])){$ext=strtolower(pathinfo($_FILES['avatar']['name'],PATHINFO_EXTENSION));if(!in_array($ext,['jpg','jpeg','png','webp']))throw new Exception('La foto debe ser JPG, PNG o WEBP.');if($_FILES['avatar']['size']>2*1024*1024)throw new Exception('La foto no puede superar 2 MB.');$avatar='avatar_'.$id.'_'.bin2hex(random_bytes(4)).'.'.$ext;move_uploaded_file($_FILES['avatar']['tmp_name'],__DIR__.'/../../public/uploads/'.$avatar);}$this->db->prepare('UPDATE users SET name=?,email=?,phone=?,theme=?,avatar=? WHERE id=?')->execute([$name,$email,$phone,$theme,$avatar,$id]);$_SESSION['user']=array_merge($u,['name'=>$name,'email'=>$email,'phone'=>$phone,'theme'=>$theme,'avatar'=>$avatar]);flash('Perfil actualizado.');}
   if(in_array($page,['pqrs','contacto'],true)){$type=$page==='pqrs'?($_POST['type']??'sugerencia'):'soporte';$subject=trim($_POST['subject']??'');$message=trim($_POST['message']??'');if($subject===''||$message==='')throw new Exception('Completa el asunto y el mensaje.');$this->db->prepare('INSERT INTO support_tickets(user_id,type,subject,message) VALUES(?,?,?,?)')->execute([$id,$type,$subject,$message]);flash('Tu mensaje fue enviado al equipo de Cashly.');}
   if($page==='articulos' && $action==='save_article'){
    $aid=(int)($_POST['id']??0);
    $title=trim($_POST['title']??''); $category=trim($_POST['category']??'Educación financiera');
    $body=trim($_POST['body']??''); $published=(int)!empty($_POST['published']);
    $image=trim($_POST['image_url']??'');
    if($title===''||$body==='') throw new Exception('El título y el contenido son obligatorios.');
    if($aid && $image==='') $image=trim($_POST['current_image']??'');
    if(isset($_FILES['image_file']) && $_FILES['image_file']['error']!==UPLOAD_ERR_NO_FILE){
        if($_FILES['image_file']['error']!==UPLOAD_ERR_OK) throw new Exception('No se pudo subir la imagen. Inténtalo nuevamente.');
        if($_FILES['image_file']['size']>5*1024*1024) throw new Exception('La imagen no puede superar 5 MB.');
        $imageInfo=@getimagesize($_FILES['image_file']['tmp_name']);
        if(!$imageInfo || !in_array($imageInfo[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) throw new Exception('La imagen debe ser JPG, PNG o WEBP.');
        $ext=($imageInfo[2]===IMAGETYPE_JPEG)?'jpg':(($imageInfo[2]===IMAGETYPE_PNG)?'png':'webp');
        $filename='consejo_'.$id.'_'.bin2hex(random_bytes(6)).'.'.$ext;
        $uploadDir=__DIR__.'/../../public/uploads/';
        if(!is_dir($uploadDir) && !mkdir($uploadDir,0755,true)) throw new Exception('No se pudo preparar la carpeta de imágenes.');
        if(!move_uploaded_file($_FILES['image_file']['tmp_name'],$uploadDir.$filename)) throw new Exception('No se pudo guardar la imagen en el servidor.');
        $image=$filename;
    }
    if($aid){
        $this->db->prepare('UPDATE articles SET title=?,category=?,image_url=?,body=?,published=? WHERE id=?')->execute([$title,$category,$image,$body,$published,$aid]);
    }else{
        $this->db->prepare('INSERT INTO articles(title,category,image_url,body,published,author_id) VALUES(?,?,?,?,?,?)')->execute([$title,$category,$image,$body,$published,$id]);
    }
    flash('Consejo guardado correctamente'.($image!==''?' con su imagen.':'.'));
}
   if($page==='articulos' && $action==='delete_article'){$this->db->prepare('DELETE FROM articles WHERE id=?')->execute([(int)$_POST['id']]);flash('Artículo eliminado.');}
   if($page==='usuarios' && $action==='user_status'){$this->db->prepare("UPDATE users SET status=? WHERE id=? AND role<>'admin'")->execute([$_POST['status']==='inactive'?'inactive':'active',(int)$_POST['id']]);flash('Estado de cuenta actualizado.');}
   if($page==='usuarios' && $action==='delete_user'){$this->db->prepare("DELETE FROM users WHERE id=? AND role<>'admin'")->execute([(int)$_POST['id']]);flash('Usuario eliminado.');}
   if($page==='usuarios' && $action==='create_user'){$name=trim($_POST['name']??'');$email=trim($_POST['email']??'');$pass=$_POST['password']??'';if($name===''||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<8||!preg_match('/[A-Z]/',$pass)||!preg_match('/[0-9]/',$pass)||!preg_match('/[^a-zA-Z0-9]/',$pass))throw new Exception('Datos inválidos; usa una contraseña de mínimo 8 caracteres, con mayúscula, número y símbolo.');$this->db->prepare('INSERT INTO users(name,email,password_hash,role) VALUES(?,?,?,?)')->execute([$name,$email,password_hash($pass,PASSWORD_DEFAULT),$_POST['role']==='admin'?'admin':'user']);flash('Usuario creado.');}
   if($page==='soporte' && in_array($action,['ticket_status','ticket_reply'],true)){$tid=(int)($_POST['id']??0);if($action==='ticket_status'){$status=$_POST['status']??'open';if(!in_array($status,['open','in_progress','closed'],true))throw new Exception('Estado no válido.');$this->db->prepare('UPDATE support_tickets SET status=? WHERE id=?')->execute([$status,$tid]);flash('Estado de la solicitud actualizado.');}else{$reply=trim($_POST['reply']??'');if($reply==='')throw new Exception('Escribe una respuesta antes de enviarla.');$this->db->prepare("UPDATE support_tickets SET reply_text=?,replied_at=NOW(),status=IF(status='open','in_progress',status) WHERE id=?")->execute([$reply,$tid]);flash('Respuesta enviada al usuario.');}}
  } catch(Throwable $e){ flash($e instanceof PDOException?'No se pudo guardar. Verifica que el correo o los datos no estén duplicados.':$e->getMessage(),'error'); }
  redirect(in_array($page,['pqrs','contacto'],true)?'ayuda':$page);
 }
 private function renderPdfReport(string $page): void {
  $user=current_user();
  $esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
  $fmt=function($v){return '$'.number_format((float)$v,0,',','.');};
  $isAdmin=$page==='admin';
  if(!$isAdmin){
   $uid=(int)$user['id'];
   $start=date('Y-m-01'); $end=date('Y-m-t');
   $rows=$this->all("SELECT movement_date AS report_date,IF(type='income','Ingreso','Gasto') AS report_type,category,description,payment_method,amount FROM movements WHERE user_id=? AND movement_date BETWEEN ? AND ? UNION ALL SELECT gd.deposit_date,'Abono de ahorro',CONCAT('Ahorro: ',g.name),gd.note,'Ahorro',gd.amount FROM goal_deposits gd JOIN savings_goals g ON g.id=gd.goal_id WHERE gd.user_id=? AND gd.deposit_date BETWEEN ? AND ? UNION ALL SELECT be.expense_date,'Gasto de presupuesto',CONCAT('Presupuesto: ',b.name),be.description,'Presupuesto',be.amount FROM budget_expenses be JOIN budgets b ON b.id=be.budget_id AND b.user_id=be.user_id WHERE be.user_id=? AND be.expense_date BETWEEN ? AND ? ORDER BY report_date DESC",[$uid,$start,$end,$uid,$start,$end,$uid,$start,$end]);
   $income=0;$expense=0;$deposits=0;foreach($rows as $r){if($r['report_type']==='Ingreso')$income+=(float)$r['amount'];elseif($r['report_type']==='Abono de ahorro')$deposits+=(float)$r['amount'];else $expense+=(float)$r['amount'];}
   $title='Informe financiero mensual';$subtitle='Periodo: '.date('d/m/Y',strtotime($start)).' al '.date('d/m/Y',strtotime($end));
  }else{
   $stats=$this->one("SELECT (SELECT COUNT(*) FROM users WHERE role='user') users,(SELECT COUNT(*) FROM users WHERE role='user' AND status='active') active,(SELECT COUNT(*) FROM savings_goals) goals,(SELECT COUNT(*) FROM budgets) budgets,(SELECT COUNT(*) FROM movements) movements,(SELECT COUNT(*) FROM support_tickets) tickets");
   $months=$this->all("SELECT period, SUM(users_count) users_count, SUM(movement_count) movement_count, SUM(income_total) income_total, SUM(expense_total) expense_total FROM (SELECT DATE_FORMAT(created_at,'%Y-%m') period,COUNT(*) users_count,0 movement_count,0 income_total,0 expense_total FROM users WHERE created_at>=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 5 MONTH),'%Y-%m-01') AND role='user' GROUP BY DATE_FORMAT(created_at,'%Y-%m') UNION ALL SELECT DATE_FORMAT(movement_date,'%Y-%m'),0,COUNT(*),SUM(IF(type='income',amount,0)),SUM(IF(type='expense',amount,0)) FROM movements WHERE movement_date>=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY DATE_FORMAT(movement_date,'%Y-%m') UNION ALL SELECT DATE_FORMAT(expense_date,'%Y-%m'),0,COUNT(*),0,SUM(amount) FROM budget_expenses WHERE expense_date>=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY DATE_FORMAT(expense_date,'%Y-%m') UNION ALL SELECT DATE_FORMAT(deposit_date,'%Y-%m'),0,COUNT(*),0,SUM(amount) FROM goal_deposits WHERE deposit_date>=DATE_FORMAT(DATE_SUB(CURDATE(),INTERVAL 5 MONTH),'%Y-%m-01') GROUP BY DATE_FORMAT(deposit_date,'%Y-%m')) x GROUP BY period ORDER BY period DESC");
   $title='Informe administrativo de Cashly';$subtitle='Generado el '.date('d/m/Y H:i');
  }
  ?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $esc($title) ?> · Cashly</title><style>
  *{box-sizing:border-box}body{font-family:Arial,sans-serif;color:#243b53;margin:32px;font-size:12px}header{border-bottom:3px solid #438dd5;padding-bottom:14px;margin-bottom:22px}h1{font-size:23px;margin:0 0 7px;color:#245b91}p{margin:4px 0;color:#60758a}.meta{font-size:11px}.cards{display:flex;gap:12px;margin:18px 0}.card{flex:1;border:1px solid #dce7f2;border-radius:9px;padding:13px}.card span{display:block;color:#687f94;font-size:10px;margin-bottom:7px}.card strong{font-size:17px;color:#245b91}h2{font-size:15px;margin:24px 0 10px;color:#245b91}table{width:100%;border-collapse:collapse}th,td{padding:8px 7px;border-bottom:1px solid #e5edf5;text-align:left;vertical-align:top}th{background:#edf5fc;color:#315f88;font-size:10px}td.amount{text-align:right;white-space:nowrap;font-weight:bold}.empty{padding:18px;text-align:center;color:#71859a;border:1px dashed #cbd9e7;border-radius:8px}.foot{margin-top:25px;border-top:1px solid #dce7f2;padding-top:10px;color:#8293a3;font-size:10px}.actions{margin-bottom:18px}.actions button{background:#438dd5;color:white;border:0;border-radius:7px;padding:10px 16px;font-weight:bold;cursor:pointer}@media print{body{margin:14mm}.actions{display:none}thead{display:table-header-group}tr{break-inside:avoid}h2{break-after:avoid}}
  </style></head><body><div class="actions"><button onclick="window.print()">Guardar / imprimir como PDF</button> <button onclick="window.close()" style="background:#71859a">Cerrar</button></div><header><h1><?= $esc($title) ?></h1><p><?= $esc($subtitle) ?></p><p class="meta">Cashly · <?= $esc($user['name']??'Administración') ?><?= !$isAdmin?' · '. $esc($user['email']??''):'' ?></p></header>
  <?php if(!$isAdmin): ?><div class="cards"><div class="card"><span>Ingresos del mes</span><strong><?= $fmt($income) ?></strong></div><div class="card"><span>Gastos del mes</span><strong><?= $fmt($expense) ?></strong></div><div class="card"><span>Abonos de ahorro</span><strong><?= $fmt($deposits) ?></strong></div><div class="card"><span>Balance de movimientos</span><strong><?= $fmt($income-$expense) ?></strong></div></div><h2>Todos los movimientos del mes</h2><?php if(!$rows): ?><div class="empty">No hay movimientos registrados durante este mes.</div><?php else: ?><table><thead><tr><th>Fecha</th><th>Tipo</th><th>Categoría</th><th>Descripción</th><th>Método</th><th style="text-align:right">Valor</th></tr></thead><tbody><?php foreach($rows as $r): ?><tr><td><?= $esc(date('d/m/Y',strtotime($r['report_date']))) ?></td><td><?= $esc($r['report_type']) ?></td><td><?= $esc($r['category']) ?></td><td><?= $esc($r['description']) ?></td><td><?= $esc($r['payment_method']) ?></td><td class="amount"><?= $fmt($r['amount']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <?php else: ?><div class="cards"><div class="card"><span>Usuarios registrados</span><strong><?= (int)$stats['users'] ?></strong></div><div class="card"><span>Cuentas activas</span><strong><?= (int)$stats['active'] ?></strong></div><div class="card"><span>Metas creadas</span><strong><?= (int)$stats['goals'] ?></strong></div><div class="card"><span>Presupuestos creados</span><strong><?= (int)$stats['budgets'] ?></strong></div><div class="card"><span>Movimientos</span><strong><?= (int)$stats['movements'] ?></strong></div><div class="card"><span>Solicitudes de soporte</span><strong><?= (int)$stats['tickets'] ?></strong></div></div><h2>Actividad de los últimos meses</h2><?php if(!$months): ?><div class="empty">Todavía no hay actividad mensual para mostrar.</div><?php else: ?><table><thead><tr><th>Mes</th><th>Usuarios nuevos</th><th>Movimientos</th><th>Ingresos registrados</th><th>Gastos registrados</th></tr></thead><tbody><?php foreach($months as $m): ?><tr><td><?= $esc(date('m/Y',strtotime($m['period'].'-01'))) ?></td><td><?= (int)$m['users_count'] ?></td><td><?= (int)$m['movement_count'] ?></td><td class="amount"><?= $fmt($m['income_total']) ?></td><td class="amount"><?= $fmt($m['expense_total']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?><?php endif; ?><div class="foot">Documento generado desde Cashly. Los datos corresponden a los registros almacenados en la plataforma.</div><script>window.addEventListener('load',function(){setTimeout(function(){window.print()},350)});</script></body></html><?php
 }
 private function all(string $sql,array $params=[]): array{$q=$this->db->prepare($sql);$q->execute($params);return $q->fetchAll();}
 private function one(string $sql,array $params=[]): array{$q=$this->db->prepare($sql);$q->execute($params);return $q->fetch()?:[];}
 private function getData(string $page): array {
  $u=current_user();$id=(int)$u['id'];$d=['page'=>$page,'user'=>$u];
  if($page==='notificaciones'){$d['notifications']=$this->all('SELECT * FROM notifications WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 100',[$id]);}
  $d['unreadNotifications']=(int)$this->one('SELECT COUNT(*) total FROM notifications WHERE user_id=? AND read_at IS NULL',[$id])['total'];
  if($page==='resumen'){
   $d['weeklyTotals']=$this->one("SELECT COALESCE(SUM(income),0) income,COALESCE(SUM(expense),0) expense FROM (SELECT IF(type='income',amount,0) income,IF(type='expense',amount,0) expense FROM movements WHERE user_id=? AND movement_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) UNION ALL SELECT 0,amount FROM budget_expenses WHERE user_id=? AND expense_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) UNION ALL SELECT 0,amount FROM goal_deposits WHERE user_id=? AND deposit_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)) a",[$id,$id,$id]);
   $d['monthlyTotals']=$this->one("SELECT COALESCE(SUM(income),0) income,COALESCE(SUM(expense),0) expense FROM (SELECT IF(type='income',amount,0) income,IF(type='expense',amount,0) expense FROM movements WHERE user_id=? AND YEAR(movement_date)=YEAR(CURDATE()) AND MONTH(movement_date)=MONTH(CURDATE()) UNION ALL SELECT 0,amount FROM budget_expenses WHERE user_id=? AND YEAR(expense_date)=YEAR(CURDATE()) AND MONTH(expense_date)=MONTH(CURDATE()) UNION ALL SELECT 0,amount FROM goal_deposits WHERE user_id=? AND YEAR(deposit_date)=YEAR(CURDATE()) AND MONTH(deposit_date)=MONTH(CURDATE())) a",[$id,$id,$id]);
   $d['weeklyRows']=$this->all("SELECT category,SUM(amount) total FROM (SELECT category,amount FROM movements WHERE user_id=? AND type='expense' AND movement_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) UNION ALL SELECT CONCAT('Presupuesto: ',b.name),be.amount FROM budget_expenses be JOIN budgets b ON b.id=be.budget_id WHERE be.user_id=? AND be.expense_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) UNION ALL SELECT CONCAT('Ahorro: ',g.name),gd.amount FROM goal_deposits gd JOIN savings_goals g ON g.id=gd.goal_id WHERE gd.user_id=? AND gd.deposit_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)) x GROUP BY category ORDER BY total DESC LIMIT 8",[$id,$id,$id]);
   $d['weeklyIncomeRows']=$this->all("SELECT category,SUM(amount) total FROM movements WHERE user_id=? AND type='income' AND movement_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) GROUP BY category ORDER BY total DESC LIMIT 8",[$id]);
   $d['monthlyRows']=$this->all("SELECT category,SUM(amount) total FROM (SELECT category,amount FROM movements WHERE user_id=? AND type='expense' AND YEAR(movement_date)=YEAR(CURDATE()) AND MONTH(movement_date)=MONTH(CURDATE()) UNION ALL SELECT CONCAT('Presupuesto: ',b.name),be.amount FROM budget_expenses be JOIN budgets b ON b.id=be.budget_id WHERE be.user_id=? AND YEAR(be.expense_date)=YEAR(CURDATE()) AND MONTH(be.expense_date)=MONTH(CURDATE()) UNION ALL SELECT CONCAT('Ahorro: ',g.name),gd.amount FROM goal_deposits gd JOIN savings_goals g ON g.id=gd.goal_id WHERE gd.user_id=? AND YEAR(gd.deposit_date)=YEAR(CURDATE()) AND MONTH(gd.deposit_date)=MONTH(CURDATE())) x GROUP BY category ORDER BY total DESC LIMIT 8",[$id,$id,$id]);
   $d['monthlyIncomeRows']=$this->all("SELECT category,SUM(amount) total FROM movements WHERE user_id=? AND type='income' AND YEAR(movement_date)=YEAR(CURDATE()) AND MONTH(movement_date)=MONTH(CURDATE()) GROUP BY category ORDER BY total DESC LIMIT 8",[$id]);
  }
  if($page==='inicio' || $page==='graficas'){
   $d['week']=$this->all("SELECT activity_date AS movement_date,SUM(income) AS income,SUM(expense) AS expense FROM (SELECT movement_date AS activity_date,IF(type='income',amount,0) AS income,IF(type='expense',amount,0) AS expense FROM movements WHERE user_id=? AND movement_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) UNION ALL SELECT expense_date AS activity_date,0 AS income,amount AS expense FROM budget_expenses WHERE user_id=? AND expense_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY) UNION ALL SELECT deposit_date AS activity_date,0 AS income,amount AS expense FROM goal_deposits WHERE user_id=? AND deposit_date>=DATE_SUB(CURDATE(),INTERVAL 6 DAY)) activity GROUP BY activity_date ORDER BY activity_date",[$id,$id,$id]);
   $d['month']=$this->all("SELECT activity_date AS movement_date,SUM(income) AS income,SUM(expense) AS expense FROM (SELECT movement_date AS activity_date,IF(type='income',amount,0) AS income,IF(type='expense',amount,0) AS expense FROM movements WHERE user_id=? AND movement_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND movement_date<=CURDATE() UNION ALL SELECT expense_date AS activity_date,0 AS income,amount AS expense FROM budget_expenses WHERE user_id=? AND expense_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND expense_date<=CURDATE() UNION ALL SELECT deposit_date AS activity_date,0 AS income,amount AS expense FROM goal_deposits WHERE user_id=? AND deposit_date>=DATE_FORMAT(CURDATE(),'%Y-%m-01') AND deposit_date<=CURDATE()) activity GROUP BY activity_date ORDER BY activity_date",[$id,$id,$id]);
   $d['categories']=$this->all("SELECT type,category,SUM(total) AS total FROM (SELECT type,category,amount AS total FROM movements WHERE user_id=? UNION ALL SELECT 'expense' AS type,CONCAT('Presupuesto: ',b.name) AS category,be.amount AS total FROM budget_expenses be JOIN budgets b ON b.id=be.budget_id AND b.user_id=be.user_id WHERE be.user_id=? UNION ALL SELECT 'expense' AS type,CONCAT('Ahorro: ',g.name) AS category,gd.amount AS total FROM goal_deposits gd JOIN savings_goals g ON g.id=gd.goal_id AND g.user_id=gd.user_id WHERE gd.user_id=?) activity GROUP BY type,category ORDER BY total DESC",[$id,$id,$id]);
   $d['totals']=$this->one("SELECT COALESCE((SELECT SUM(amount) FROM movements WHERE user_id=? AND type='income'),0) income,(COALESCE((SELECT SUM(amount) FROM movements WHERE user_id=? AND type='expense'),0)+COALESCE((SELECT SUM(amount) FROM budget_expenses WHERE user_id=?),0)+COALESCE((SELECT SUM(amount) FROM goal_deposits WHERE user_id=?),0)) expense",[$id,$id,$id,$id]);
  }
  if($page==='inicio'){$d['movements']=$this->all('SELECT * FROM movements WHERE user_id=? ORDER BY movement_date DESC,id DESC',[$id]);$d['homeDeposits']=$this->all("SELECT gd.id,gd.deposit_date AS movement_date, g.name AS category, gd.amount, gd.note AS description, 'Ahorro' AS payment_method, 'deposit' AS activity_kind, 'expense' AS type FROM goal_deposits gd JOIN savings_goals g ON g.id=gd.goal_id WHERE gd.user_id=? ORDER BY gd.deposit_date DESC,gd.id DESC",[$id]);$d['homeBudgetExpenses']=$this->all("SELECT be.id,be.expense_date AS movement_date, CONCAT('Presupuesto: ',b.name) AS category,be.amount,COALESCE(be.description,'') AS description,'Presupuesto' AS payment_method,'budget_expense' AS activity_kind,'expense' AS type FROM budget_expenses be INNER JOIN budgets b ON b.id=be.budget_id AND b.user_id=be.user_id WHERE be.user_id=? ORDER BY be.expense_date DESC,be.id DESC",[$id]);$d['homeGoals']=$this->all('SELECT g.id,g.name,g.target_amount,g.initial_amount+COALESCE((SELECT SUM(amount) FROM goal_deposits gd WHERE gd.goal_id=g.id),0) saved FROM savings_goals g WHERE g.user_id=? ORDER BY g.id DESC LIMIT 3',[$id]);$d['homeArticles']=$this->all('SELECT id,title,body AS summary,category FROM articles WHERE published=1 ORDER BY created_at DESC LIMIT 4');}
  if($page==='metas'){$d['goals']=$this->all('SELECT g.*,g.initial_amount+COALESCE((SELECT SUM(amount) FROM goal_deposits gd WHERE gd.goal_id=g.id),0) saved FROM savings_goals g WHERE user_id=? ORDER BY id DESC',[$id]);$d['deposits']=$this->all('SELECT gd.*,g.name goal_name FROM goal_deposits gd JOIN savings_goals g ON g.id=gd.goal_id WHERE gd.user_id=? ORDER BY deposit_date DESC,id DESC LIMIT 100',[$id]);}
  if($page==='presupuestos'){$d['budgets']=$this->all('SELECT b.*,b.initial_spent+COALESCE((SELECT SUM(amount) FROM budget_expenses be WHERE be.budget_id=b.id),0) spent FROM budgets b WHERE user_id=? ORDER BY id DESC',[$id]);$d['budgetExpenses']=$this->all('SELECT be.*,b.name budget_name FROM budget_expenses be JOIN budgets b ON b.id=be.budget_id WHERE be.user_id=? ORDER BY expense_date DESC,id DESC LIMIT 100',[$id]);}
  if($page==='calendario'){$d['events']=$this->all('SELECT * FROM reminders WHERE user_id=? ORDER BY event_date ASC',[$id]);$d['movements']=$this->all('SELECT * FROM movements WHERE user_id=? ORDER BY movement_date DESC LIMIT 100',[$id]);$d['dayStats']=$this->all("SELECT movement_date,SUM(IF(type='expense',amount,0)) expenses,SUM(IF(type='income',amount,0)) incomes FROM movements WHERE user_id=? GROUP BY movement_date ORDER BY expenses DESC LIMIT 1",[$id]);}
  if($page==='consejos')$d['articles']=$this->all('SELECT * FROM articles WHERE published=1 ORDER BY created_at DESC');
  if($page==='perfil')$d['profile']=$this->one('SELECT name,email,phone,theme,avatar,created_at FROM users WHERE id=?',[$id]);
  if(in_array($page,['admin','estadisticas'],true)){$d['stats']=$this->one("SELECT (SELECT COUNT(*) FROM users WHERE role='user') users,(SELECT COUNT(*) FROM users WHERE role='user' AND status='active') active,(SELECT COUNT(*) FROM savings_goals) goals,(SELECT COUNT(*) FROM budgets) budgets,(SELECT COUNT(*) FROM movements) movements");$d['activity']=$this->all('SELECT name,email,created_at,status FROM users ORDER BY created_at DESC LIMIT 8');$d['growth']=$this->all("SELECT DATE_FORMAT(created_at,'%Y-%m') month,COUNT(*) total FROM users WHERE role='user' GROUP BY month ORDER BY month DESC LIMIT 6");$d['dailyGrowth']=$this->all("SELECT DATE(created_at) day,COUNT(*) total FROM users WHERE role='user' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(created_at) ORDER BY day ASC");$d['popularCategories']=$this->all("SELECT category,COUNT(*) total FROM movements GROUP BY category ORDER BY total DESC LIMIT 6");$d['expenseCategories']=$this->all("SELECT category,SUM(amount) total FROM movements WHERE type='expense' GROUP BY category ORDER BY total DESC LIMIT 6");}
  if($page==='usuarios')$d['users']=$this->all("SELECT id,name,email,phone,role,status,created_at,last_login FROM users ORDER BY created_at DESC");
  if($page==='articulos')$d['articles']=$this->all('SELECT * FROM articles ORDER BY created_at DESC');
  if($page==='soporte'){$d['tickets']=$this->all("SELECT t.*,u.name,u.email FROM support_tickets t JOIN users u ON u.id=t.user_id ORDER BY FIELD(t.status,'open','in_progress','closed'),t.created_at DESC");$d['ticketStats']=$this->one("SELECT COUNT(*) total,SUM(status='open') open_count,SUM(status='in_progress') progress_count,SUM(status='closed') closed_count FROM support_tickets");}
  if(in_array($page,['ayuda','pqrs','contacto'],true)){
   $ticketFilter = $page==='pqrs' ? "type<>'soporte'" : ($page==='contacto' ? "type='soporte'" : '1=1');
   $d['tickets']=$this->all("SELECT * FROM support_tickets WHERE user_id=? AND $ticketFilter ORDER BY created_at DESC LIMIT 30",[$id]);
  }
  return $d;
 }
}

