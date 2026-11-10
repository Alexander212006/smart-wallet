<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (($argv[1] ?? '') !== '--run') { fwrite(STDERR, "Usage: php tests/database_programs_test.php --run\n"); exit(2); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../database/sql_runner.php';
require_once __DIR__ . '/../database/program_support.php';
require_once __DIR__ . '/../includes/database_procedures.php';
$pdo = getDatabaseConnection();
$serverVersion = wallet_check_program_support($pdo);
$original = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
$database = 'smart_wallet_program_test_' . bin2hex(random_bytes(6));
$assertions = 0;
function program_assert(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}
function program_call(string $name, array $parameters): array
{
    global $pdo;
    return wallet_call_procedure($pdo, $name, $parameters);
}
function program_balance(int $wallet): string
{
    global $pdo;
    $query=$pdo->prepare('SELECT current_balance FROM wallets WHERE id=?');
    $query->execute([$wallet]);
    return (string) $query->fetchColumn();
}
function program_reject(callable $operation, string $message, bool $businessError = true): void
{
    $rejected=false;
    try { $operation(); } catch (PDOException $error) {
        $rejected=!$businessError || ($error->errorInfo[0] ?? '')==='45000';
    }
    program_assert($rejected,$message);
}
try {
    $pdo->exec('CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $database . '`');
    $schema=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../database/schema.sql'));
    $schema=preg_replace('/^USE smart_wallet;\s*$/m','',$schema);
    $schema=preg_replace('/CREATE DATABASE IF NOT EXISTS smart_wallet\s+CHARACTER SET utf8mb4\s+COLLATE utf8mb4_unicode_ci;/','',$schema);
    foreach (explode(";\n",$schema) as $statement) { if (trim($statement)!=='') { $pdo->query($statement)->closeCursor(); } }
    foreach (['views.sql','triggers.sql','procedures.sql'] as $file) { wallet_execute_sql_file($pdo,__DIR__.'/../database/'.$file); }
    foreach (['ROUTINES'=>['ROUTINE_SCHEMA',18],'VIEWS'=>['TABLE_SCHEMA',5],'TRIGGERS'=>['TRIGGER_SCHEMA',7]] as $table=>[$column,$expected]) {
        program_assert((int)$pdo->query("SELECT COUNT(*) FROM information_schema.$table WHERE $column=DATABASE()")->fetchColumn()===$expected,'Expected database object count: '.$table);
    }
    $pdo->exec("INSERT INTO users(name,email,password_hash) VALUES('Program test','program-test@example.test','test-only'),('Other user','other-program@example.test','test-only')");
    $user=1;$other=2;
    $wallet=(int)program_call('sp_create_wallet',[$user,'Everyday','cash','1000.00','PHP'])[0]['id'];
    $usd=(int)program_call('sp_create_wallet',[$user,'Travel','cash','500.00','USD'])[0]['id'];
    $archived=(int)program_call('sp_create_wallet',[$user,'Old wallet','cash','200.00','PHP'])[0]['id'];
    $foreign=(int)program_call('sp_create_wallet',[$other,'Private wallet','cash','500.00','PHP'])[0]['id'];
    program_call('sp_update_wallet',[$user,$wallet,'Renamed','checking']);
    program_assert($pdo->query('SELECT name FROM wallets WHERE id='.$wallet)->fetchColumn()==='Renamed','Wallet procedure updates its name.');
    program_call('sp_set_wallet_archived',[$user,$archived,1]);
    program_reject(fn()=>program_call('sp_update_wallet',[$other,$wallet,'Stolen','cash']),'Wallet updates enforce ownership.');
    program_reject(fn()=>program_call('sp_set_wallet_archived',[$other,$wallet,1]),'Wallet archive enforces ownership.');
    program_reject(fn()=>program_call('sp_create_wallet',[$user,'','cash','0.00','PHP']),'Invalid wallet names are rejected.');
    program_reject(fn()=>program_call('sp_create_wallet',[$user,'Bad','invalid','0.00','PHP']),'Invalid wallet types are rejected.');
    program_reject(fn()=>program_call('sp_create_wallet',[$user,'Bad','cash','0.00','ZZZ']),'Unsupported currencies are rejected.');
    $date='2024-03-10 12:00:00';
    $income=(int)program_call('sp_save_transaction',[$user,null,$wallet,'Salary','100.00',null,$date,null])[0]['id'];
    $expense=(int)program_call('sp_save_transaction',[$user,null,$wallet,'Food','-20.00',null,$date,'essential'])[0]['id'];
    program_assert(program_balance($wallet)==='1080.00','Transaction insert triggers apply each signed amount once.');
    program_call('sp_save_transaction',[$user,$expense,$wallet,'Food','-25.00','Edited',$date,'non_essential']);
    program_assert(program_balance($wallet)==='1075.00','Editing an amount applies only its delta.');
    program_call('sp_save_transaction',[$user,$expense,$usd,'Food','-30.00',null,$date,null]);
    program_assert(program_balance($wallet)==='1100.00' && program_balance($usd)==='470.00','Moving transactions restores the old wallet and updates the new one.');
    program_reject(fn()=>program_call('sp_save_transaction',[$user,$income,$wallet,'Salary','-1.00',null,$date,null]),'Procedure prevents changing transaction direction.');
    program_reject(fn()=>program_call('sp_save_transaction',[$other,$income,$foreign,'Salary','1.00',null,$date,null]),'Transaction edit enforces ownership.');
    program_reject(fn()=>program_call('sp_save_transaction',[$user,null,$foreign,'Food','-1.00',null,$date,null]),'Foreign wallets are rejected.');
    program_reject(fn()=>program_call('sp_save_transaction',[$user,null,$archived,'Food','-1.00',null,$date,null]),'Archived wallet selection is rejected by procedures.');
    program_reject(fn()=>program_call('sp_save_transaction',[$user,null,$wallet,'Food','0.00',null,$date,null]),'Zero transaction amounts are rejected.');
    program_reject(fn()=>program_call('sp_save_transaction',[$user,null,$wallet,'','-1.00',null,$date,null]),'Empty categories are rejected.');
    program_reject(fn()=>program_call('sp_delete_transaction',[$user,$expense,'income']),'Delete checks the expected direction.');
    program_call('sp_delete_transaction',[$user,$expense,'expense']);
    program_call('sp_delete_transaction',[$user,$income,'income']);
    program_assert(program_balance($wallet)==='1000.00' && program_balance($usd)==='500.00','Deletes reverse the signed effects.');
    $insert=$pdo->prepare('INSERT INTO transactions(user_id,wallet_id,category,amount,transaction_date,expense_nature) VALUES(?,?,?,?,?,?)');
    $insert->execute([$user,$wallet,'Direct SQL','-10.00',$date,null]);$direct=(int)$pdo->lastInsertId();
    program_assert(program_balance($wallet)==='990.00','Direct INSERT also runs balance triggers.');
    program_reject(fn()=>$pdo->exec('UPDATE transactions SET user_id='.$other.' WHERE id='.$direct),'Direct UPDATE cannot transfer transaction ownership.');
    $pdo->exec('UPDATE transactions SET amount=-15.00 WHERE id='.$direct);
    program_assert(program_balance($wallet)==='985.00','Direct UPDATE also runs balance triggers.');
    $pdo->exec('UPDATE transactions SET wallet_id='.$usd.' WHERE id='.$direct);
    program_assert(program_balance($wallet)==='1000.00' && program_balance($usd)==='485.00','Direct wallet changes apply correct deltas.');
    $pdo->exec('DELETE FROM transactions WHERE id='.$direct);
    program_assert(program_balance($usd)==='500.00','Direct DELETE reverses its amount.');
    program_reject(fn()=>$insert->execute([$user,$foreign,'Invalid','-1.00',$date,null]),'Trigger blocks cross-user wallet references.');
    program_reject(fn()=>$insert->execute([$user,$wallet,'Invalid','0.00',$date,null]),'Trigger blocks zero amounts.');
    program_reject(fn()=>$insert->execute([$user,$wallet,'Invalid','1.00',$date,'essential']),'Trigger blocks expense labels on income.');
    $maximum=(int)program_call('sp_create_wallet',[$user,'Maximum','cash','999999999999.99','PHP'])[0]['id'];
    $count=(int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
    program_reject(fn()=>program_call('sp_save_transaction',[$user,null,$maximum,'Overflow','0.01',null,$date,null]),'Balance overflow rolls back the transaction.',false);
    program_assert(program_balance($maximum)==='999999999999.99' && (int)$pdo->query('SELECT COUNT(*) FROM transactions')->fetchColumn()===$count,'Failed balance updates leave both tables unchanged.');
    program_call('sp_set_wallet_archived',[$user,$maximum,1]);
    $pdo->beginTransaction();
    program_reject(fn()=>program_call('sp_create_wallet',[$user,'Nested','cash','0.00','PHP']),'Mutating procedures reject nested transactions.');
    program_assert($pdo->inTransaction(),'A rejected nested call does not commit or roll back its caller.');
    $pdo->rollBack();

    // A caller with pending writes must retain both its work and control of its transaction.
    $pdo->beginTransaction();
    $pdo->exec("UPDATE wallets SET name='Caller pending change' WHERE id=".$wallet);
    program_reject(fn()=>program_call('sp_update_wallet',[$user,$wallet,'Unexpected commit','cash']), 'A nested write call is rejected without committing caller changes.');
    program_assert($pdo->inTransaction() && $pdo->query('SELECT name FROM wallets WHERE id='.$wallet)->fetchColumn()==='Caller pending change', 'Rejected calls preserve pending caller writes.');
    $pdo->rollBack();
    program_assert($pdo->query('SELECT name FROM wallets WHERE id='.$wallet)->fetchColumn()!=='Caller pending change', 'Caller work remains reversible after a rejected procedure.');
    $pdo->exec('SET SESSION autocommit=0');
    try {
        program_reject(fn()=>program_call('sp_create_wallet',[$user,'Autocommit disabled','cash','0.00','PHP']), 'Procedures reject callers that disabled autocommit.');
    } finally {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $pdo->exec('SET SESSION autocommit=1');
    }

    foreach ([[$user,$wallet,'Salary','20.00',$date,null],[$user,$wallet,'Food','-10.00',$date,'essential'],
        [$user,$wallet,'Food','-5.00',$date,null],[$user,$wallet,'Food','-900.00','2024-03-11 12:00:00',null],
        [$user,$usd,'Food','-7.00',$date,null],[$user,$archived,'Food','-40.00',$date,null],[$other,$foreign,'Secret','-70.00',$date,null]] as $row) {$insert->execute($row);}
    $summary=program_call('sp_get_monthly_summary',[$user,'PHP','2024-03-01'])[0];
    program_assert($summary['income']==='20.00' && $summary['expenses']==='915.00','Summary view preserves monthly totals and currency/user scope.');
    program_assert($summary['balance']==='105.00','Wallet summary excludes archived wallets.');
    $rows=program_call('sp_get_spending_rows',[$user,'PHP','2024-03-01','2024-03-11']);
    program_assert(count($rows)===2 && array_sum(array_map(fn($row)=>(float)$row['amount'],$rows))===15.0,'Spending rows scope dates, user, active wallets and currency.');
    $cash=program_call('sp_get_cash_flow',[$user,'PHP','2024-03-01','2024-03-11']);
    program_assert(count($cash)===1 && $cash[0]['income']==='20.00' && $cash[0]['expenses']==='15.00','Cash flow groups the requested days.');
    $categories=program_call('sp_get_category_spending',[$user,'PHP','2024-03-01']);
    program_assert(count($categories)===1 && $categories[0]['name']==='Food' && (int)$categories[0]['transaction_count']===3,'Category view provides counts and totals.');

    $goal=(int)program_call('sp_save_savings_goal',[$user,null,'Emergency fund',null,'100.00','PHP','2027-01-01'])[0]['id'];
    program_reject(fn()=>program_call('sp_contribute_savings',[$user,$goal,$usd,'1.00',$date,null]),'Savings contribution enforces currency matching.');
    program_reject(fn()=>program_call('sp_contribute_savings',[$other,$goal,null,'1.00',$date,null]),'Savings contribution enforces goal ownership.');
    program_call('sp_set_savings_goal_status',[$user,$goal,'paused']);
    program_reject(fn()=>program_call('sp_contribute_savings',[$user,$goal,null,'1.00',$date,null]),'Paused goals cannot receive contributions.');
    program_call('sp_set_savings_goal_status',[$user,$goal,'active']);
    $balance=program_balance($wallet);
    program_assert(program_call('sp_contribute_savings',[$user,$goal,$wallet,'40.00',$date,null])[0]['status']==='active','Partial contributions keep the goal active.');
    program_assert(program_call('sp_contribute_savings',[$user,$goal,null,'60.00',$date,null])[0]['status']==='completed','Contribution trigger completes a reached target.');
    program_assert(program_balance($wallet)===$balance,'Savings contributions remain tracking entries without wallet debits.');
    $progress=$pdo->query('SELECT * FROM vw_savings_goal_progress WHERE id='.$goal)->fetch();
    program_assert($progress['saved']==='100.00' && (int)$progress['contribution_count']===2,'Savings view summarizes contributions.');
    $contributionId=(int)$pdo->query('SELECT id FROM savings_contributions WHERE savings_goal_id='.$goal.' ORDER BY id LIMIT 1')->fetchColumn();
    $edited=program_call('sp_update_savings_contribution',[$user,$goal,$contributionId,$wallet,'10.00','2024-03-09 12:00:00','Corrected note'])[0];
    program_assert($edited['status']==='active' && $edited['saved']==='70.00','Reducing a completed contribution reopens the goal and recalculates its total.');
    $stored=$pdo->query('SELECT * FROM savings_contributions WHERE id='.$contributionId)->fetch();
    program_assert($stored['note']==='Corrected note' && $stored['contribution_date']==='2024-03-09 12:00:00','Contribution edits persist the note and date.');
    program_assert(program_balance($wallet)===$balance,'Editing a contribution does not debit or credit its wallet.');
    foreach ([[$other,$goal,$contributionId,$wallet,'10.00',$date,null],[$user,$goal,$contributionId,$foreign,'10.00',$date,null],
        [$user,$goal,$contributionId,$usd,'10.00',$date,null],[$user,$goal,$contributionId,$archived,'10.00',$date,null],
        [$user,$goal,$contributionId,null,'0.00',$date,null],[$user,$goal,$contributionId,null,'-1.00',$date,null],
        [$user,$goal,0,null,'10.00',$date,null],[$user,$goal,$contributionId,null,'10.00',null,null]] as $invalid) {
        program_reject(fn()=>program_call('sp_update_savings_contribution',$invalid),'Contribution edits reject invalid ownership, wallets, amounts, dates and IDs.');
    }
    $otherGoal=(int)program_call('sp_save_savings_goal',[$user,null,'Other goal',null,'100.00','PHP','2027-01-01'])[0]['id'];
    program_reject(fn()=>program_call('sp_update_savings_contribution',[$user,$otherGoal,$contributionId,null,'1.00',$date,null]),'Contribution edits cannot move a record to another goal.');
    program_call('sp_delete_savings_goal',[$user,$otherGoal]);
    program_call('sp_set_wallet_archived',[$user,$wallet,1]);
    program_assert(program_call('sp_update_savings_contribution',[$user,$goal,$contributionId,$wallet,'40.00',$date,null])[0]['status']==='completed','The existing archived account can be retained while correcting history.');
    program_call('sp_set_wallet_archived',[$user,$wallet,0]);
    foreach (['paused','cancelled'] as $preservedStatus) {
        program_call('sp_set_savings_goal_status',[$user,$goal,$preservedStatus]);
        program_assert(program_call('sp_update_savings_contribution',[$user,$goal,$contributionId,null,'40.00',$date,null])[0]['status']===$preservedStatus,'Historical edits preserve a paused or cancelled goal status.');
    }
    program_call('sp_set_savings_goal_status',[$user,$goal,'active']);
    program_assert(program_call('sp_update_savings_contribution',[$user,$goal,$contributionId,$wallet,'40.00',$date,null])[0]['status']==='completed','Increasing a contribution to the target completes an active goal.');
    program_reject(fn()=>program_call('sp_save_savings_goal',[$user,$goal,'Changed',null,'100.00','USD',null]),'Contributed goals cannot change currency.');
    program_reject(fn()=>program_call('sp_delete_savings_goal',[$other,$goal]),'Goal deletion enforces ownership.');
    program_call('sp_delete_savings_goal',[$user,$goal]);
    program_assert((int)$pdo->query('SELECT COUNT(*) FROM savings_contributions WHERE savings_goal_id='.$goal)->fetchColumn()===0,'Goal deletion cascades to contribution history.');
    $directGoal=(int)program_call('sp_save_savings_goal',[$user,null,'Direct contribution',null,'5.00','PHP','2027-01-01'])[0]['id'];
    $pdo->prepare('INSERT INTO savings_contributions(savings_goal_id,wallet_id,amount,contribution_date) VALUES(?,NULL,5.00,?)')->execute([$directGoal,$date]);
    program_assert($pdo->query('SELECT status FROM savings_goals WHERE id='.$directGoal)->fetchColumn()==='completed','Direct contribution INSERT also completes its savings goal.');
    program_assert(program_balance($wallet)===$balance,'Direct contribution triggers do not debit wallets.');
    program_call('sp_delete_savings_goal',[$user,$directGoal]);

    $limit=(int)program_call('sp_save_expense_limit',[$user,null,'Food limit','Food','15.00','PHP','monthly','2024-03-01',null,1])[0]['id'];
    $limits=program_call('sp_get_expense_limits',[$user,'2024-03-10']);
    program_assert(count($limits)===1 && $limits[0]['spent']==='15.00' && (int)$limits[0]['range_active']===1,'Limits exclude future expenses and preserve category/currency/user scope.');
    $futureLimit=program_call('sp_get_expense_limits',[$user,'2023-12-31'])[0];
    program_assert((int)$futureLimit['range_active']===0 && (float)$futureLimit['spent']===0.0, 'Limits beginning in a later year remain inactive without unsigned date arithmetic errors.');
    program_call('sp_set_expense_limit_recurring',[$user,$limit,0,'2024-03-09']);
    program_assert((int)program_call('sp_get_expense_limits',[$user,'2024-03-10'])[0]['range_active']===0,'Stopped limits become inactive after their end date.');
    program_call('sp_set_expense_limit_recurring',[$user,$limit,1,'2024-03-10']);
    program_assert((int)program_call('sp_get_expense_limits',[$user,'2024-03-10'])[0]['range_active']===1,'Resumed limits use their original schedule.');
    program_reject(fn()=>program_call('sp_save_expense_limit',[$user,null,'Invalid',null,'10.00','PHP','monthly','2024-01-31','2024-02-27',0]),'One-time limits must include a full clamped period.');
    $recurring=(int)program_call('sp_save_expense_limit',[$user,null,'Month-end','Recurrence','10.00','PHP','monthly','2024-01-31',null,1])[0]['id'];
    $find=static function(array $rows,int $id):array {foreach($rows as $row){if((int)$row['id']===$id)return$row;}throw new RuntimeException('Missing limit.');};
    $monthEnd=$find(program_call('sp_get_expense_limits',[$user,'2024-02-29']),$recurring);
    program_assert($monthEnd['range_start']==='2024-02-29' && $monthEnd['range_end']==='2024-03-31','Monthly recurrence clamps leap February without March drift.');
    $yearly=(int)program_call('sp_save_expense_limit',[$user,null,'Leap-year','Recurrence','10.00','PHP','yearly','2024-02-29',null,1])[0]['id'];
    $yearEnd=$find(program_call('sp_get_expense_limits',[$user,'2025-02-28']),$yearly);
    program_assert($yearEnd['range_start']==='2025-02-28' && $yearEnd['range_end']==='2026-02-28','Yearly recurrence clamps leap day.');
    program_reject(fn()=>program_call('sp_delete_expense_limit',[$other,$limit]),'Limit deletion enforces ownership.');
    program_call('sp_delete_expense_limit',[$user,$limit]);
    program_assert((int)$pdo->query('SELECT COUNT(*) FROM expense_limits WHERE id='.$limit)->fetchColumn()===0,'Limit procedure deletes its record.');
    $snapshot=$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll();
    foreach (['views.sql','triggers.sql','procedures.sql'] as $file) {wallet_execute_sql_file($pdo,__DIR__.'/../database/'.$file);}
    program_assert($snapshot===$pdo->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),'Reinstalling database programs does not recalculate balances.');
    $pdo->beginTransaction();
    program_reject(fn()=>program_call('sp_create_wallet',[$user,'Nested after reinstall','cash','0.00','PHP']), 'Reinstalled routines retain the engine-specific transaction guard.');
    program_assert($pdo->inTransaction(), 'Reinstalled routines leave caller transactions open after rejection.');
    $pdo->rollBack();

    $tables=['users','wallets','transactions','savings_goals','savings_contributions','expense_limits'];
    $demoSnapshot=[];
    foreach ($tables as $table) { $demoSnapshot[$table]=$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(); }
    wallet_execute_sql_file($pdo,__DIR__.'/../database/case_study_examples.sql');
    program_assert(!$pdo->inTransaction(), 'The case-study SQL demonstration finishes its transaction.');
    foreach ($tables as $table) {
        program_assert($demoSnapshot[$table]===$pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(), 'The case-study demonstration rolls back its changes to '.$table.'.');
    }
} finally {
    if($pdo->inTransaction()){$pdo->rollBack();}
    $pdo->exec('USE `'.str_replace('`','``',$original).'`');
    if(preg_match('/\Asmart_wallet_program_test_[a-f0-9]{12}\z/',$database)!==1){throw new RuntimeException('Unexpected test database name.');}
    $pdo->exec('DROP DATABASE IF EXISTS `'.$database.'`');
}
echo 'Database program tests passed ('.$assertions.' assertions) on '.$serverVersion."; disposable database removed.\n";
