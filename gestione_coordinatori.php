<?php
session_start();
if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }
require_once 'config.php';

// Estrazione sicura dell'ID utente e dell'organizzazione dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? $_SESSION['utente']['ORGANIZZAZIONE_ID'] ?? null;

// Normalizzazione del ruolo e dei permessi
$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? $_SESSION['utente']['RUOLO'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? false;
if (!$is_super_admin && ($ruolo_lower === 'super_admin' || $ruolo_lower === 'admin' || $ruolo_lower === 'superadmin')) {
    $is_super_admin = true;
}

$is_capo_personale = ($ruolo_lower === 'capo_personale' || $ruolo_lower === 'capopersonale');

// Controllo accesso: accessibile solo se è Super Admin oppure Capo Personale
if (empty($utente_id) || (!$is_super_admin && !$is_capo_personale)) {
    header("Location: index.php");
    exit;
}

$messaggio = '';
$tipo_alert = '';

// Gestione Azioni POST (Creazione, Modifica, Cancellazione)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? 'crea';

    // 1. ELIMINAZIONE COORDINATORE
    if ($azione === 'elimina') {
        $coord_id_da_eliminare = trim($_POST['coordinatore_id'] ?? '');
        if (!empty($coord_id_da_eliminare)) {
            $del_response = supabase_request('staging_utenti', '?id=eq.' . $coord_id_da_eliminare, 'DELETE');
            $del_status = is_array($del_response) ? ($del_response['status'] ?? 200) : 200;
            
            // PostgREST DELETE restituisce spesso 204 No Content o 200
            if ($del_status === 200 || $del_status === 204 || (is_array($del_response) && !isset($del_response['error']))) {
                $messaggio = "Coordinatore eliminato con successo!";
                $tipo_alert = "success";
            } else {
                $messaggio = "Errore durante l'eliminazione (Codice HTTP: " . $del_status . ").";
                $tipo_alert = "danger";
            }
        }
    }
    // 2. MODIFICA COORDINATORE
    elseif ($azione === 'modifica') {
        $coord_id = trim($_POST['coordinatore_id'] ?? '');
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $reparto_id = trim($_POST['reparto_id'] ?? '');
        $nuova_password = $_POST['password'] ?? '';

        if (!empty($coord_id) && !empty($nome) && !empty($email) && !empty($reparto_id)) {
            $dati_aggiornamento = [
                'nome' => $nome,
                'email' => $email,
                'reparto_id' => $reparto_id
            ];

            // Aggiorna la password solo se è stata inserita
            if (!empty(trim($nuova_password))) {
                $dati_aggiornamento['password_hash'] = password_hash($nuova_password, PASSWORD_DEFAULT);
            }

            $patch_response = supabase_request('staging_utenti', '?id=eq.' . $coord_id, 'PATCH', $dati_aggiornamento);
            $patch_status = is_array($patch_response) ? ($patch_response['status'] ?? 200) : 200;

            // Consideriamo valida la risposta se lo status è 200/204 oppure se non è presente un errore esplicito nell'array
            $ha_errore = is_array($patch_response) && isset($patch_response['error']);

            if (!$ha_errore && ($patch_status === 200 || $patch_status === 204 || $patch_status === 0)) {
                $messaggio = "Coordinatore aggiornato con successo!";
                $tipo_alert = "success";
            } else {
                $err_msg = is_array($patch_response) && isset($patch_response['error']) ? ' (' . json_encode($patch_response['error']) . ')' : '';
                $messaggio = "Errore durante l'aggiornamento (Codice HTTP: " . $patch_status . ")." . $err_msg;
                $tipo_alert = "danger";
            }
        } else {
            $messaggio = "Tutti i campi obbligatori per la modifica devono essere compilati.";
            $tipo_alert = "warning";
        }
    }
    // 3. CREAZIONE NUOVO COORDINATORE
    else {
        $nome = trim($_POST['nome'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password_in_chiaro = $_POST['password'] ?? '';
        $reparto_id = trim($_POST['reparto_id'] ?? '');
        
        $organizzazione_id = $is_super_admin ? trim($_POST['organizzazione_id'] ?? '') : $org_id_utente;

        if (!empty($nome) && !empty($email) && !empty($password_in_chiaro) && !empty($organizzazione_id) && !empty($reparto_id)) {
            $password_hash = password_hash($password_in_chiaro, PASSWORD_DEFAULT);
            
            $nuovo_coordinatore = [
                'organizzazione_id' => $organizzazione_id,
                'reparto_id' => $reparto_id,
                'nome' => $nome,
                'email' => $email,
                'ruolo' => 'coordinatore',
                'password_hash' => $password_hash,
                'is_super_admin' => false
            ];

            $response = supabase_request('staging_utenti', '', 'POST', $nuovo_coordinatore);
            $status = is_array($response) ? ($response['status'] ?? 201) : 201;

            if ($status === 201 || $status === 200 || $status === 204 || (is_array($response) && !isset($response['error']))) {
                $messaggio = "Coordinatore creato con successo!";
                $tipo_alert = "success";
            } else {
                $check_resp = supabase_request('staging_utenti', '?email=eq.' . urlencode($email) . '&select=id');
                $check_data = is_array($check_resp) ? ($check_resp['data'] ?? $check_resp) : [];
                
                if (!empty($check_data)) {
                    $messaggio = "Coordinatore creato con successo!";
                    $tipo_alert = "success";
                } else {
                    $messaggio = "Errore durante la creazione (Codice HTTP: " . $status . ").";
                    $tipo_alert = "danger";
                }
            }
        } else {
            $messaggio = "Tutti i campi obbligatori (incluso il reparto) devono essere compilati.";
            $tipo_alert = "warning";
        }
    }
}

// Recupera le organizzazioni (se Super Admin)
$organizzazioni = [];
if ($is_super_admin) {
    $org_response = supabase_request('organizzazioni', '?select=id,nome_struttura');
    $org_status = is_array($org_response) ? ($org_response['status'] ?? 200) : 200;
    $org_data = is_array($org_response) ? ($org_response['data'] ?? $org_response) : [];
    if ($org_status === 200 && is_array($org_data)) {
        $organizzazioni = $org_data;
    }
}

// Recupera l'elenco dei reparti
$reparti_query = '?select=id,nome_reparto,organizzazione_id';
if (!$is_super_admin && !empty($org_id_utente)) {
    $reparti_query .= '&organizzazione_id=eq.' . $org_id_utente;
}
$reparti_response = supabase_request('reparti', $reparti_query);
$reparti_status = is_array($reparti_response) ? ($reparti_response['status'] ?? 200) : 200;
$reparti_data = is_array($reparti_response) ? ($reparti_response['data'] ?? $reparti_response) : [];
$reparti = ($reparti_status === 200 && is_array($reparti_data)) ? $reparti_data : [];

// Recupera l'elenco dei coordinatori esistenti dalla tabella staging_utenti
$query_coordinatori = '?ruolo=eq.coordinatore&select=id,nome,email,organizzazione_id,reparto_id';
if (!$is_super_admin && !empty($org_id_utente)) {
    $query_coordinatori .= '&organizzazione_id=eq.' . $org_id_utente;
}

$coord_response = supabase_request('staging_utenti', $query_coordinatori);
$coord_status = is_array($coord_response) ? ($coord_response['status'] ?? 200) : 200;
$coord_data = is_array($coord_response) ? ($coord_response['data'] ?? $coord_response) : [];
$coordinatori = ($coord_status === 200 && is_array($coord_data)) ? $coord_data : [];

// Mappa dei reparti per ID
$mappa_reparti = [];
foreach ($reparti as $rep) {
    $mappa_reparti[$rep['id']] = $rep['nome_reparto'];
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gestione Coordinatori</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; padding-bottom: 70px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .nav-bottom { position: fixed; bottom: 0; width: 100%; height: 60px; background: white; border-top: 1px solid #ddd; display: flex; justify-content: space-around; align-items: center; z-index: 1000; }
        .header-mobile { background: #0d6efd; color: white; padding: 20px; border-radius: 0 0 20px 20px; }
    </style>
</head>
<body>

<div class="header-mobile mb-4">
    <div class="container d-flex justify-content-between align-items-center">
        <div>
            <h5 class="mb-0"><?php echo $is_super_admin ? 'Super Admin Panel' : 'Capo Personale Panel'; ?></h5>
            <small>Gestione Coordinatori</small>
        </div>
        <a href="dashboard.php" class="text-white text-decoration-none"><i class="bi bi-arrow-left fs-4"></i></a>
    </div>
</div>

<div class="container">
    <?php if (!empty($messaggio)): ?>
        <div class="alert alert-<?php echo $tipo_alert; ?> alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($messaggio); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <!-- Modulo Creazione Coordinatore -->
    <div class="card p-4 mb-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-person-badge-fill me-2 text-primary"></i>Crea Nuovo Coordinatore</h5>
        
        <form method="POST" action="gestione_coordinatori.php">
            <input type="hidden" name="azione" value="crea">
            <div class="mb-3">
                <label for="nome" class="form-label">Nome e Cognome</label>
                <input type="text" class="form-control" id="nome" name="nome" required>
            </div>
            <div class="mb-3">
                <label for="email" class="form-label">Email (Login)</label>
                <input type="email" class="form-control" id="email" name="email" required>
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Password temporanea</label>
                <input type="password" class="form-control" id="password" name="password" required>
            </div>

            <?php if ($is_super_admin): ?>
                <div class="mb-3">
                    <label for="organizzazione_id" class="form-label">Organizzazione di appartenenza</label>
                    <select class="form-select" id="organizzazione_id" name="organizzazione_id" required>
                        <option value="">Seleziona un'organizzazione...</option>
                        <?php foreach ($organizzazioni as $org): ?>
                            <option value="<?php echo htmlspecialchars($org['id'] ?? ''); ?>">
                                <?php echo htmlspecialchars($org['nome_struttura'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php else: ?>
                <input type="hidden" name="organizzazione_id" value="<?php echo htmlspecialchars($org_id_utente ?? ''); ?>">
            <?php endif; ?>

            <div class="mb-3">
                <label for="reparto_id" class="form-label">Reparto di assegnazione</label>
                <select class="form-select" id="reparto_id" name="reparto_id" required>
                    <option value="">Seleziona un reparto...</option>
                    <?php if (!empty($reparti)): ?>
                        <?php foreach ($reparti as $rep): ?>
                            <option value="<?php echo htmlspecialchars($rep['id'] ?? ''); ?>">
                                <?php echo htmlspecialchars($rep['nome_reparto'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <button type="submit" class="btn btn-primary w-100 py-2 fw-bold">Crea Coordinatore</button>
        </form>
    </div>

    <!-- Elenco Coordinatori Esistenti con Azioni Modifica / Elimina -->
    <div class="card p-4">
        <h5 class="fw-bold mb-3"><i class="bi bi-people-fill me-2 text-secondary"></i>Coordinatori Registrati</h5>
        <?php if (empty($coordinatori)): ?>
            <p class="text-muted mb-0">Nessun Coordinatore presente nel sistema.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Email</th>
                            <th>Reparto Assegnato</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($coordinatori as $coord): 
                            $coord_id = $coord['id'] ?? '';
                            $rep_id_coord = $coord['reparto_id'] ?? '';
                            $nome_reparto_coord = $mappa_reparti[$rep_id_coord] ?? ($rep_id_coord ? 'Reparto ID: ' . $rep_id_coord : 'Non assegnato');
                        ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($coord['nome'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($coord['email'] ?? ''); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($nome_reparto_coord); ?></span></td>
                                <td class="text-end">
                                    <!-- Pulsante Modifica -->
                                    <button class="btn btn-sm btn-outline-primary me-1" data-bs-toggle="modal" data-bs-target="#modaleModifica<?php echo $coord_id; ?>">
                                        <i class="bi bi-pencil-fill"></i>
                                    </button>
                                    <!-- Pulsante Elimina -->
                                    <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#modaleElimina<?php echo $coord_id; ?>">
                                        <i class="bi bi-trash-fill"></i>
                                    </button>
                                </td>
                            </tr>

                            <!-- MODALE MODIFICA COORDINATORE -->
                            <div class="modal fade" id="modaleModifica<?php echo $coord_id; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST" action="gestione_coordinatori.php">
                                            <input type="hidden" name="azione" value="modifica">
                                            <input type="hidden" name="coordinatore_id" value="<?php echo $coord_id; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Modifica Coordinatore</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body text-start">
                                                <div class="mb-3">
                                                    <label class="form-label">Nome e Cognome</label>
                                                    <input type="text" class="form-control" name="nome" value="<?php echo htmlspecialchars($coord['nome'] ?? ''); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Email (Login)</label>
                                                    <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($coord['email'] ?? ''); ?>" required>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Reparto Assegnato</label>
                                                    <select class="form-select" name="reparto_id" required>
                                                        <option value="">Seleziona un reparto...</option>
                                                        <?php foreach ($reparti as $rep): ?>
                                                            <option value="<?php echo htmlspecialchars($rep['id']); ?>" <?php echo ($rep['id'] === $rep_id_coord) ? 'selected' : ''; ?>>
                                                                <?php echo htmlspecialchars($rep['nome_reparto']); ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="mb-3">
                                                    <label class="form-label">Nuova Password (lascia vuoto per non variare)</label>
                                                    <input type="password" class="form-control" name="password" placeholder="Inserisci nuova password">
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                                                <button type="submit" class="btn btn-primary">Salva Modifiche</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                            <!-- MODALE ELIMINA COORDINATORE -->
                            <div class="modal fade" id="modaleElimina<?php echo $coord_id; ?>" tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="POST" action="gestione_coordinatori.php">
                                            <input type="hidden" name="azione" value="elimina">
                                            <input type="hidden" name="coordinatore_id" value="<?php echo $coord_id; ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Conferma Eliminazione</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                            </div>
                                            <div class="modal-body text-start">
                                                <p>Sei sicuro di voler eliminare il coordinatore <strong><?php echo htmlspecialchars($coord['nome']); ?></strong>?</p>
                                                <p class="text-danger small mb-0">Questa azione è irreversibile.</p>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                                                <button type="submit" class="btn btn-danger">Elimina Definitivamente</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="nav-bottom">
    <a href="dashboard.php" class="text-secondary text-decoration-none"><i class="bi bi-house-door fs-4"></i></a>
    <a href="dashboard.php" class="text-primary text-decoration-none"><i class="bi bi-grid fs-4"></i></a>
    <a href="logout.php" class="text-danger text-decoration-none"><i class="bi bi-box-arrow-right fs-4"></i></a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>