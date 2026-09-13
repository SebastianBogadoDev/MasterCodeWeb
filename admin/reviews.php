<?php
/* admin/reviews.php — panel privado de moderación de reseñas.
   GET: lista pendientes (con email, visible solo aquí) y publicadas.
   POST: aprobar / rechazar / despublicar. Requiere sesión admin + CSRF.
   Patrón PRG (Post/Redirect/Get) tras cada mutación para evitar reenvíos. */

require_once dirname(__DIR__) . '/config/bootstrap.php';
require_once dirname(__DIR__) . '/config/admin-auth.php';
require_once dirname(__DIR__) . '/config/reviews-store.php';

adminPageHeaders();
requireAdminAuth();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $id     = (string)($_POST['id']     ?? '');

    if (!adminCsrfValidate()) {
        $msg  = 'Sesión de seguridad expirada. Vuelve a intentarlo.';
        $type = 'error';
    } elseif ($id === '') {
        $msg  = 'Falta el identificador de la reseña.';
        $type = 'error';
    } else {
        switch ($action) {
            case 'approve':
                $verified = !empty($_POST['verified_customer']);
                $result   = reviewApprove($id, $verified);
                $msg      = $result['ok'] ? 'Reseña aprobada y publicada.' : ($result['error'] ?? 'Error al aprobar.');
                $type     = $result['ok'] ? 'success' : 'error';
                break;
            case 'reject':
                $result = reviewReject($id);
                $msg    = $result['ok'] ? 'Reseña rechazada.' : ($result['error'] ?? 'Error al rechazar.');
                $type   = $result['ok'] ? 'success' : 'error';
                break;
            case 'unpublish':
                $result = reviewUnpublish($id);
                $msg    = $result['ok'] ? 'Reseña despublicada.' : ($result['error'] ?? 'Error al despublicar.');
                $type   = $result['ok'] ? 'success' : 'error';
                break;
            default:
                $msg  = 'Acción no reconocida.';
                $type = 'error';
        }
    }

    header('Location: reviews.php?msg=' . rawurlencode($msg) . '&type=' . rawurlencode($type));
    exit;
}

$flashMsg  = isset($_GET['msg'])  ? (string)$_GET['msg']  : '';
$flashType = isset($_GET['type']) && $_GET['type'] === 'error' ? 'error' : 'success';

$pending  = reviewsReadPending();
$approved = reviewsReadApproved();

usort($pending,  fn($a, $b) => strcmp($a['created_at'] ?? '', $b['created_at'] ?? '')); // más antiguas primero
usort($approved, fn($a, $b) => strcmp($b['created_at'] ?? '', $a['created_at'] ?? '')); // más recientes primero

$csrfToken   = csrfGenerate();
$logoutToken = $csrfToken; // mismo token de sesión, se regenera tras cada uso

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function stars(int $rating): string
{
    $rating = max(0, min(5, $rating));
    return str_repeat('★', $rating) . str_repeat('☆', 5 - $rating);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Moderación de reseñas | MasterCodeWeb</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="/css/main.bundle.css">
    <link rel="stylesheet" href="/css/pages/admin-reviews.css">
</head>
<body class="admin-body">

    <header class="admin-header">
        <div class="admin-header__inner">
            <h1 class="admin-header__title">Moderación de reseñas</h1>
            <form method="post" action="logout.php">
                <input type="hidden" name="_csrf" value="<?= h($logoutToken) ?>">
                <button type="submit" class="btn btn--secondary admin-header__logout">Cerrar sesión</button>
            </form>
        </div>
    </header>

    <main class="admin-main">

        <?php if ($flashMsg !== ''): ?>
            <p class="admin-alert admin-alert--<?= $flashType === 'error' ? 'error' : 'success' ?>" role="status">
                <?= h($flashMsg) ?>
            </p>
        <?php endif; ?>

        <!-- ==================== PENDIENTES ==================== -->
        <section class="admin-section" aria-labelledby="pending-title">
            <h2 id="pending-title" class="admin-section__title">
                Pendientes <span class="admin-count"><?= count($pending) ?></span>
            </h2>

            <?php if (empty($pending)): ?>
                <p class="admin-empty">No hay reseñas pendientes de moderación.</p>
            <?php else: ?>
                <div class="admin-grid">
                    <?php foreach ($pending as $r): ?>
                        <?php
                        $id      = (string)($r['id'] ?? '');
                        $name    = (string)($r['name'] ?? '');
                        $email   = (string)($r['email'] ?? '');
                        $rating  = (int)($r['rating'] ?? 0);
                        $comment = (string)($r['comment'] ?? '');
                        $service = (string)($r['service'] ?? '');
                        $pdate   = (string)($r['project_date'] ?? '');
                        $created = (string)($r['created_at'] ?? '');
                        $hasAvatar = !empty($r['avatar']);
                        ?>
                        <article class="admin-card">
                            <header class="admin-card__header">
                                <?php if ($hasAvatar): ?>
                                    <img class="admin-card__avatar" src="avatar.php?id=<?= urlencode($id) ?>" alt="Foto de perfil enviada por <?= h($name) ?> (pendiente de revisión)" width="48" height="48" loading="lazy">
                                <?php else: ?>
                                    <span class="admin-card__avatar admin-card__avatar--initial" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                                <?php endif; ?>
                                <div>
                                    <p class="admin-card__name"><?= h($name) ?></p>
                                    <p class="admin-card__email"><?= h($email) ?></p>
                                </div>
                                <span class="admin-card__stars" aria-label="<?= $rating ?> de 5 estrellas"><?= stars($rating) ?></span>
                            </header>

                            <p class="admin-card__comment"><?= nl2br(h($comment)) ?></p>

                            <dl class="admin-card__meta">
                                <div><dt>Servicio</dt><dd><?= h($service ?: '—') ?></dd></div>
                                <div><dt>Fecha proyecto</dt><dd><?= h($pdate ?: '—') ?></dd></div>
                                <div><dt>Enviado</dt><dd><?= h($created) ?></dd></div>
                            </dl>

                            <div class="admin-card__actions">
                                <form method="post" class="admin-card__approve-form">
                                    <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id" value="<?= h($id) ?>">
                                    <label class="form-check admin-card__verify-check">
                                        <input type="checkbox" name="verified_customer" value="1">
                                        <span>Cliente verificado (he comprobado el registro del proyecto)</span>
                                    </label>
                                    <button type="submit" class="btn btn--primary admin-btn--approve">Aprobar</button>
                                </form>

                                <form method="post" class="js-confirm-reject">
                                    <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="id" value="<?= h($id) ?>">
                                    <button type="submit" class="btn admin-btn--reject">Rechazar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- ==================== PUBLICADAS ==================== -->
        <section class="admin-section" aria-labelledby="approved-title">
            <h2 id="approved-title" class="admin-section__title">
                Publicadas <span class="admin-count"><?= count($approved) ?></span>
            </h2>

            <?php if (empty($approved)): ?>
                <p class="admin-empty">Todavía no hay reseñas publicadas.</p>
            <?php else: ?>
                <div class="admin-grid">
                    <?php foreach ($approved as $r): ?>
                        <?php
                        $id       = (string)($r['id'] ?? '');
                        $name     = (string)($r['name'] ?? '');
                        $rating   = (int)($r['rating'] ?? 0);
                        $comment  = (string)($r['comment'] ?? '');
                        $service  = (string)($r['service'] ?? '');
                        $pdate    = (string)($r['project_date'] ?? '');
                        $verified = ($r['verified_customer'] ?? false) === true;
                        $avatar   = (string)($r['avatar'] ?? '');
                        ?>
                        <article class="admin-card admin-card--approved">
                            <header class="admin-card__header">
                                <?php if ($avatar !== ''): ?>
                                    <img class="admin-card__avatar" src="<?= h($avatar) ?>" alt="Foto de perfil de <?= h($name) ?>" width="48" height="48" loading="lazy">
                                <?php else: ?>
                                    <span class="admin-card__avatar admin-card__avatar--initial" aria-hidden="true"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                                <?php endif; ?>
                                <div>
                                    <p class="admin-card__name">
                                        <?= h($name) ?>
                                        <?php if ($verified): ?>
                                            <span class="admin-badge admin-badge--verified">Cliente verificado</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="admin-card__stars" aria-label="<?= $rating ?> de 5 estrellas"><?= stars($rating) ?></span>
                            </header>

                            <p class="admin-card__comment"><?= nl2br(h($comment)) ?></p>

                            <dl class="admin-card__meta">
                                <div><dt>Servicio</dt><dd><?= h($service ?: '—') ?></dd></div>
                                <div><dt>Fecha proyecto</dt><dd><?= h($pdate ?: '—') ?></dd></div>
                            </dl>

                            <div class="admin-card__actions">
                                <form method="post" class="js-confirm-reject">
                                    <input type="hidden" name="_csrf" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="unpublish">
                                    <input type="hidden" name="id" value="<?= h($id) ?>">
                                    <button type="submit" class="btn admin-btn--reject">Despublicar</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </main>

    <script>
    document.querySelectorAll('.js-confirm-reject').forEach(function (form) {
      form.addEventListener('submit', function (e) {
        var action = form.querySelector('input[name="action"]').value;
        var text = action === 'unpublish'
          ? '¿Despublicar esta reseña? Dejará de mostrarse públicamente.'
          : '¿Rechazar esta reseña? Esta acción no se puede deshacer.';
        if (!window.confirm(text)) {
          e.preventDefault();
        }
      });
    });
    </script>

</body>
</html>
