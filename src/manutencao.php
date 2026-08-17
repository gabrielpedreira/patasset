<?php
$flag = __DIR__ . '/manutencao.flag';
if (!file_exists($flag)) { header('Location: index.html'); exit; }
$msg = trim(file_get_contents($flag)) ?: 'O sistema estará de volta em breve.';
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sistema em Manutenção — PatAsset</title>
<link rel="icon" type="image/png" href="logo_1.png">
<style>
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%);font-family:'Segoe UI',sans-serif;}
.box{background:#fff;border-radius:18px;padding:48px 40px;max-width:420px;width:100%;
  text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.icon{font-size:64px;margin-bottom:20px;}
h1{color:#1e3a5f;font-size:22px;font-weight:700;margin:0 0 12px;}
p{color:#64748b;font-size:14px;line-height:1.6;margin:0;}
.badge{display:inline-block;margin-top:24px;padding:8px 20px;
  background:#fef3c7;color:#92400e;border-radius:20px;font-size:13px;font-weight:600;}
</style>
</head>
<body>
<div class="box">
  <div class="icon">🔧</div>
  <h1>Sistema em Manutenção</h1>
  <p><?= htmlspecialchars($msg) ?></p>
  <div class="badge">⏳ Aguarde...</div>
</div>
</body>
</html>
