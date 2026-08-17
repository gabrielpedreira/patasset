<?php
// EASTER EGG — remover quando solicitado
// Injeta sonic-idle.js apenas para usuário "usuario_exemplo" (classe PATRIMONIO)
$_sonic_user    = $_SESSION['usuario_logado']    ?? '';
$_sonic_classe  = $_SESSION['classe_usuario']    ?? '';
// Verifica classe na sessão; se não estiver na sessão, busca do banco apenas para usuario_exemplo
if (strtolower($_sonic_user) === 'usuario_exemplo') :
?>
<script>window.SONIC_USER = "<?= htmlspecialchars($_sonic_user, ENT_QUOTES) ?>";</script>
<script src="/sonic-idle.js?v=1"></script>
<?php endif; ?>
