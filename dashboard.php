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

// Recupero dati utente con gestione ultra-sicura
$nome_reparto = 'N/D';
$dati_utente_db = @supabase_request('staging_utenti', "?id=eq.$utente_id&select=*");

if (empty($dati_utente_db) || !is_array($dati_utente_db) || isset($dati_utente_db['code']) || isset($dati_utente_db['message'])) {
    $dati_utente_db = @supabase_request('utenti', "?id=eq.$utente_id&select=*");
}

if (!empty($dati_utente_db) && is_array($dati_utente_db) && isset($dati_utente_db[0]) && is_array($dati_utente_db[0])) {
    $u_info = $dati_utente_db[0];
    
    if (!empty($u_info['nome'])) {
        $nome_utente = $u_info['nome'];
    }
    
    if (!empty($u_info['ruolo'])) {
        $ruolo_raw = $u_info['ruolo'];
        $ruolo_lower = strtolower(str_replace([' ', '-'], '_', trim($ruolo_raw)));
        if ($ruolo_lower === 'coordinatore') {
            $is_coordinatore = true;
        }
    }
    
    if (empty($reparto_id_utente)) {
        $reparto_id_utente = $u_info['reparto_id'] ?? $u_info['id_reparto'] ?? $u_info['reparto'] ?? null;
    }
    
    if (empty($org_id_utente)) {
        $org_id_utente = $u_info['organizzazione_id'] ?? $u_info['id_organizzazione'] ?? null;
    }
    
    $_SESSION['reparto_id'] = $reparto_id_utente;
    $_SESSION['organizzazione_id'] = $org_id_utente;
}

// Controllo unificato per determinare se l'utente è un admin, capo o coordinatore abilitato al riepilogo ore
$è_admin_o_coordinatore = $is_super_admin || $is_capo_personale || $is_coordinatore || 
    strpos($ruolo_lower, 'admin') !== false || 
    strpos($ruolo_lower, 'coordinat') !== false || 
    strpos($ruolo_lower, 'capo') !== false || 
    strpos($ruolo_lower, 'caposala') !== false ||
    strpos($ruolo_lower, 'responsabile') !== false;

// Recupero nome reparto e flag gestione straordinari
$gestione_straordinari_attiva = false;
if (!empty($reparto_id_utente)) {
    $dati_reparto_db = @supabase_request('reparti', "?id=eq.$reparto_id_utente&select=*");

    if (!empty($dati_reparto_db) && is_array($dati_reparto_db) && isset($dati_reparto_db[0]) && is_array($dati_reparto_db[0])) {
        $r_info = $dati_reparto_db[0];
        $nome_reparto = $r_info['nome_reparto'] ?? 'N/D';
        // Controllo del flag booleano per i straordinari sul reparto
        $gestione_straordinari_attiva = !empty($r_info['gestione_straordinari']);
    }
}

// Conteggio richieste ferie/assenze in attesa
$conteggio_da_approvare = 0;

if ($è_admin_o_coordinatore) {
    $richieste_pendenti = @supabase_request('assenze', "?select=*");

    if (is_array($richieste_pendenti) && !isset($richieste_pendenti['code']) && !isset($richieste_pendenti['message'])) {
        foreach ($richieste_pendenti as $req) {
            $status_req = strtolower(trim($req['stato'] ?? $req['status'] ?? ''));
            
            $is_pendente = (
                $status_req === 'in_attesa' || 
                $status_req === 'pendente' || 
                $status_req === 'attesa' || 
                $status_req === 'richiesta' || 
                $status_req === '' ||
                strpos($status_req, 'attesa') !== false ||
                strpos($status_req, 'pend') !== false
            );
            
            $r_reparto = $req['reparto_id'] ?? null;
            $u_req_id = $req['utente_id'] ?? null;
            
            $match_reparto = false;
            if ($is_super_admin || $is_capo_personale) {
                $match_reparto = true;
            } elseif ($is_coordinatore && !empty($reparto_id_utente)) {
                if ($r_reparto == $reparto_id_utente) {
                    $match_reparto = true;
                } else if ($u_req_id) {
                    $info_u_req = @supabase_request('staging_utenti', "?id=eq.$u_req_id&select=reparto_id");
                    if (empty($info_u_req) || isset($info_u_req['code'])) {
                        $info_u_req = @supabase_request('utenti', "?id=eq.$u_req_id&select=reparto_id");
                    }
                    if (!empty($info_u_req[0]['reparto_id']) && $info_u_req[0]['reparto_id'] == $reparto_id_utente) {
                        $match_reparto = true;
                    } else if (empty($r_reparto) && empty($info_u_req[0]['reparto_id'])) {
                        $match_reparto = true;
                    }
                } else if (empty($r_reparto)) {
                    $match_reparto = true;
                }
            }

            if ($match_reparto && $is_pendente) {
                $conteggio_da_approvare++;
            }
        }
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
                <!-- Sezione visibile solo al Super Amministratore -->
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
                <!-- Sezione Gestione Reparti visibile a Super Admin e Capo Personale -->
                <div class="col-md-4">
                    <div class="card h-100 border-start border-primary border-4">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="text-primary mb-3 fs-3"><i class="bi bi-hospital-fill"></i></div>
                                <h5 class="card-title fw-bold">Gestione Reparti</h5>
                                <p class="card-text text-muted small">Aggiungi, visualizza e gestisci i reparti operativi appartenenti alla tua struttura organizzativa.</p>
                            </div>
                            <a href="gestione_reparti.php" class="btn btn-outline-primary btn-sm mt-3 fw-bold">Gestisci Reparti <i class="bi bi-arrow-right"></i></a>
                        </div>
                    </div>
                </div>

                <!-- Sezione visibile al Super Admin e al Capo Personale / Capo Ospedale -->
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
                <!-- Sezione visibile a Super Admin, Capo Personale e Coordinatori di reparto -->
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

            <!-- BADGE / SEZIONE DEDICATA A RIEPILOGO ORE (Visibile solo a Admin, Capi e Coordinatori) -->
            <?php if ($è_admin_o_coordinatore): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-start border-primary border-4 bg-white shadow-sm">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="text-primary mb-3 fs-3"><i class="bi bi-clock-history"></i></div>
                                <h5 class="card-title fw-bold">Riepilogo Ore & Turni</h5>
                                <p class="card-text text-muted small">Consulta il prospetto mensile tabellare delle ore lavorate, straordinari e reperibilità del reparto.</p>
                            </div>
                            <a href="riepilogo_ore.php" class="btn btn-outline-primary btn-sm mt-3 fw-bold d-flex justify-content-between align-items-center">
                                <span>Apri Riepilogo Ore</span>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Sezione Straordinari: visibile a Super Admin, Capo Personale o se il reparto ha il flag attivo -->
            <?php if ($is_super_admin || $is_capo_personale || $gestione_straordinari_attiva): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-start border-dark border-4">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <div>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <div class="text-dark fs-3"><i class="bi bi-file-earmark-text-fill"></i></div>
                                </div>
                                <h5 class="card-title fw-bold">Gestione Straordinari</h5>
                                <p class="card-text text-muted small">Compilazione moduli ufficiali, generazione protocolli e riepilogo ore per dipendente e regime.</p>
                            </div>
                            <a href="straordinari.php" class="btn btn-outline-dark btn-sm mt-3 fw-bold d-flex justify-content-between align-items-center">
                                <span>Apri Straordinari</span>
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Sezione Ferie e Assenze: visibile a TUTTI (sia collaboratori che coordinatori/admin) -->
            <div class="col-md-4">
                <div class="card h-100 border-start border-info border-4">
                    <div class="card-body d-flex flex-column justify-content-between p-4">
                        <div>
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <div class="text-info fs-3"><i class="bi bi-calendar-check-fill"></i></div>
                                <?php if ($è_admin_o_coordinatore && $conteggio_da_approvare > 0): ?>
                                    <span class="badge bg-danger rounded-pill px-2 py-1" title="Richieste in attesa di approvazione">
                                        <i class="bi bi-bell-fill"></i> <?php echo $conteggio_da_approvare; ?> da approvare
                                    </span>
                                <?php endif; ?>
                            </div>
                            <h5 class="card-title fw-bold">Ferie e Assenze</h5>
                            <p class="card-text text-muted small">Inoltra nuove richieste di ferie, permessi o malattia e monitora lo stato delle autorizzazioni.</p>
                        </div>
                        <a href="ferie.php" class="btn btn-outline-info btn-sm mt-3 fw-bold text-dark d-flex justify-content-between align-items-center">
                            <span>Gestisci Ferie</span>
                            <?php if ($è_admin_o_coordinatore && $conteggio_da_approvare > 0): ?>
                                <span class="badge bg-danger"><?php echo $conteggio_da_approvare; ?></span>
                            <?php endif; ?>
                            <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Sezione profilo personale / turni personali -->
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