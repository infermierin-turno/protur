<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// Estrazione sicura dell'ID utente, dell'organizzazione e del reparto dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? $_SESSION['utente']['ORGANIZZAZIONE_ID'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? $_SESSION['utente']['REPARTO_ID'] ?? null;

// Nome utente e ruolo dalla sessione
$nome_utente = $_SESSION['nome'] ?? $_SESSION['utente']['nome'] ?? $_SESSION['utente']['NOME'] ?? 'Utente';
$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? $_SESSION['utente']['RUOLO'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? false;
if (!$is_super_admin && ($ruolo_lower === 'super_admin' || $ruolo_lower === 'admin' || $ruolo_lower === 'superadmin')) {
    $is_super_admin = true;
}

$is_capo_personale = ($ruolo_lower === 'capo_personale' || $ruolo_lower === 'capopersonale');
$is_coordinatore = ($ruolo_lower === 'coordinatore');

if (empty($utente_id)) {
    header("Location: index.php");
    exit;
}

// DEBUG ARRAY
$debug_info = [
    'utente_id' => $utente_id,
    'reparto_id_iniziale' => $reparto_id_utente,
    'query_utente' => null,
    'query_reparto' => null
];

// Recupero forzato dei dati utente da Supabase (prima provo staging_utenti, poi utenti)
$nome_reparto = 'N/D';
$dati_utente_db = supabase_request('staging_utenti', "?id=eq.$utente_id&select=*", 'GET', null, 'staging');

if (empty($dati_utente_db) || !is_array($dati_utente_db) || isset($dati_utente_db['code'])) {
    $dati_utente_db = supabase_request('utenti', "?id=eq.$utente_id&select=*");
}

$debug_info['query_utente'] = $dati_utente_db;

if (!empty($dati_utente_db) && is_array($dati_utente_db) && !isset($dati_utente_db['code']) && count($dati_utente_db) > 0) {
    $u_info = $dati_utente_db[0];
    
    if (!empty($u_info['nome'])) {
        $nome_utente = $u_info['nome'];
    }
    
    if (empty($reparto_id_utente)) {
        $reparto_id_utente = $u_info['reparto_id'] ?? $u_info['id_reparto'] ?? $u_info['reparto'] ?? $u_info['REPARTO_ID'] ?? null;
    }
    
    if (empty($org_id_utente)) {
        $org_id_utente = $u_info['organizzazione_id'] ?? $u_info['id_organizzazione'] ?? $u_info['ORGANIZZAZIONE_ID'] ?? null;
    }
    
    $_SESSION['reparto_id'] = $reparto_id_utente;
    $_SESSION['organizzazione_id'] = $org_id_utente;
}

$debug_info['reparto_id_finale'] = $reparto_id_utente;

// Se abbiamo trovato un ID reparto valido, effettuiamo la query per recuperare il nome del reparto
if (!empty($reparto_id_utente)) {
    $dati_reparto_db = supabase_request('staging_reparti', "?id=eq.$reparto_id_utente&select=*", 'GET', null, 'staging');
    
    if (empty($dati_reparto_db) || !is_array($dati_reparto_db) || isset($dati_reparto_db['code'])) {
        $dati_reparto_db = supabase_request('reparti', "?id=eq.$reparto_id_utente&select=*");
    }

    $debug_info['query_reparto'] = $dati_reparto_db;

    if (!empty($dati_reparto_db) && is_array($dati_reparto_db) && !isset($dati_reparto_db['code']) && count($dati_reparto_db) > 0) {
        $r_info = $dati_reparto_db[0];
        $nome_reparto = $r_info['nome'] ?? $r_info['nome_reparto'] ?? $r_info['descrizione'] ?? $r_info['NOME'] ?? 'N/D';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Dashboard - Gestione Turni Ospedalieri</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; padding-bottom: 70px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); transition: transform 0.2s; }
        .card:hover { transform: translateY(-3px); }
    </style>
</head>
<body class="bg-light">

    <!-- BOX DI DEBUG TEMPORANEO PER VEDERE COSA ARRIVA -->
    <div class="container mt-3">
        <div class="alert alert-warning border border-warning shadow-sm">
            <h5 class="alert-heading fw-bold"><i class="bi bi-bug-fill"></i> Pannello di Diagnostica Reparto</h5>
            <hr>
            <pre class="mb-0" style="font-size: 12px; max-height: 200px; overflow-y: auto;"><?php echo htmlspecialchars(print_r($debug_info, true)); ?></pre>
        </div>
    </div>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">Gestione Turni Ospedalieri</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link active" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="cambia_password.php">Modifica Password</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container py-4">
        <!-- Box Benvenuto con Nome e Reparto -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-white p-4 border-start border-primary border-4 shadow-sm">
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
                        <div>
                            <h2 class="fw-bold text-dark mb-1">Benvenuto, <?php echo htmlspecialchars($nome_utente); ?></h2>
                            <p class="text-muted mb-0">
                                Profilo: <span class="badge bg-secondary text-uppercase"><?php echo htmlspecialchars($ruolo_raw !== '' ? $ruolo_raw : 'Collaboratore'); ?></span>
                                | Reparto: <span class="badge bg-info text-dark"><i class="bi bi-hospital"></i> <strong><?php echo htmlspecialchars($nome_reparto); ?></strong></span>
                            </p>
                        </div>
                        <div class="mt-3 mt-md-0 d-flex align-items-center gap-3">
                            <a href="cambia_password.php" class="btn btn-outline-primary btn-sm fw-bold"><i class="bi bi-key"></i> Modifica Password</a>
                            <span class="text-muted small"><i class="bi bi-clock"></i> <?php echo date('d/m/Y'); ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <?php if ($is_super_admin): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-start border-danger border-4">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="text-danger mb-3 fs-3"><i class="bi bi-shield-lock-fill"></i></div>
                                <h5 class="card-title fw-bold">Amministrazione Globale</h5>
                                <p class="card-text text-muted small">Gestione completa della piattaforma, configurazioni di sistema e controllo totale delle organizzazioni.</p>
                            </div>
                            <a href="gestione_capi_personale.php" class="btn btn-outline-danger btn-sm mt-3 fw-bold">Gestione Capo Personale <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($is_super_admin || $is_capo_personale): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-start border-primary border-4">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="text-primary mb-3 fs-3"><i class="bi bi-person-badge-fill"></i></div>
                                <h5 class="card-title fw-bold">Gestione Coordinatori</h5>
                                <p class="card-text text-muted small">Nomina, revoca e configurazione dei coordinatori di reparto e supervisione del personale ospedaliero.</p>
                            </div>
                            <a href="gestione_coordinatori.php" class="btn btn-outline-primary btn-sm mt-3 fw-bold">Gestisci Coordinatori <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($is_super_admin || $is_capo_personale || $is_coordinatore): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-start border-success border-4">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="text-success mb-3 fs-3"><i class="bi bi-people-fill"></i></div>
                                <h5 class="card-title fw-bold">Gestione Collaboratori</h5>
                                <p class="card-text text-muted small">Anagrafica, credenziali, ruoli e assegnazione dei collaboratori e infermieri all'interno del reparto.</p>
                            </div>
                            <a href="gestione_collaboratori.php" class="btn btn-outline-success btn-sm mt-3 fw-bold">Vai a Collaboratori <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card h-100 border-start border-warning border-4">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="text-warning mb-3 fs-3"><i class="bi bi-calendar3"></i></div>
                                <h5 class="card-title fw-bold">Pianificazione Turni</h5>
                                <p class="card-text text-muted small">Creazione, modifica e visualizzazione della griglia dei turni mensili per tutto il personale del reparto.</p>
                            </div>
                            <a href="turni.php" class="btn btn-outline-warning btn-sm mt-3 fw-bold text-dark">Gestisci Turni <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="col-md-4">
                <div class="card h-100 border-start border-info border-4">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div>
                            <div class="text-info mb-3 fs-3"><i class="bi bi-calendar-check-fill"></i></div>
                            <h5 class="card-title fw-bold">Ferie e Assenze</h5>
                            <p class="card-text text-muted small">Inoltra nuove richieste di ferie, permessi o malattia e monitora lo stato delle autorizzazioni.</p>
                        </div>
                        <a href="ferie.php" class="btn btn-outline-info btn-sm mt-3 fw-bold text-dark">Gestisci Ferie <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card h-100 border-start border-secondary border-4">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div>
                            <div class="text-secondary mb-3 fs-3"><i class="bi bi-person-circle"></i></div>
                            <h5 class="card-title fw-bold">I Miei Turni</h5>
                            <p class="card-text text-muted small">Visualizza il tuo calendario personale dei turni assegnati e lo storico delle tue attività.</p>
                        </div>
                        <a href="planner.php" class="btn btn-outline-secondary btn-sm mt-3 fw-bold">Visualizza Turni <i class="bi bi-arrow-right"></i></a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>