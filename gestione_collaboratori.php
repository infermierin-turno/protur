<?php
session_start();
if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }
require_once 'config.php';

// Estrazione sicura dell'ID utente e dell'organizzazione dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? $_SESSION['utente']['ORGANIZZAZIONE_ID'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? $_SESSION['utente']['REPARTO_ID'] ?? null;

// Normalizzazione del ruolo e dei permessi
$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? $_SESSION['utente']['RUOLO'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? false;
if (!$is_super_admin && ($ruolo_lower === 'super_admin' || $ruolo_lower === 'admin' || $ruolo_lower === 'superadmin')) {
    $is_super_admin = true;
}

$is_capo_personale = ($ruolo_lower === 'capo_personale' || $ruolo_lower === 'capopersonale' || $is_super_admin);
$is_coordinatore = ($ruolo_lower === 'coordinatore');

// Controllo accesso
if (empty($utente_id) || (!$is_super_admin && !$is_capo_personale && !$is_coordinatore)) {
    header("Location: index.php");
    exit;
}

// Recupero dati aggiornati dell'utente da Supabase
if (!empty($utente_id)) {
    $dati_utente_db = supabase_request('staging_utenti', "?id=eq.$utente_id&select=reparto_id,organizzazione_id,ruolo");
    if (!empty($dati_utente_db) && is_array($dati_utente_db) && !isset($dati_utente_db['code'])) {
        if (empty($reparto_id_utente)) {
            $reparto_id_utente = $dati_utente_db[0]['reparto_id'] ?? null;
        }
        if (empty($org_id_utente)) {
            $org_id_utente = $dati_utente_db[0]['organizzazione_id'] ?? null;
        }
    }
}

// LOGICA SWITCH E VISIBILITA REPARTI
$lista_reparti_struttura = [];

if ($is_capo_personale) {
    // Il Capo Personale vede TUTTI i reparti dell'organizzazione
    if (!empty($org_id_utente)) {
        $res_rep_all = supabase_request('reparti', "?organizzazione_id=eq.$org_id_utente&select=id,nome_reparto&order=nome_reparto.asc");
        if (!empty($res_rep_all) && is_array($res_rep_all) && !isset($res_rep_all['code'])) {
            $lista_reparti_struttura = $res_rep_all;
        }
    }
} else {
    // Il Coordinatore vede SOLO i reparti a lui assegnati (es. i suoi due reparti)
    // Cerchiamo nella tabella di associazione o dal campo reparto_id / tabelle di coordinamento
    $reparti_assegnati_ids = [];
    
    // 1. Controllo se l'utente ha un reparto principale diretto
    if (!empty($reparto_id_utente)) {
        $reparti_assegnati_ids[] = $reparto_id_utente;
    }
    
    // 2. Controllo se esiste una tabella di legame coordinatori-reparti o se è associato ad altri reparti
    $res_coord_rep = supabase_request('coordinatori_reparti', "?utente_id=eq.$utente_id&select=reparto_id");
    if (!empty($res_coord_rep) && is_array($res_coord_rep) && !isset($res_coord_rep['code'])) {
        foreach ($res_coord_rep as $cr) {
            if (!empty($cr['reparto_id']) && !in_array($cr['reparto_id'], $reparti_assegnati_ids)) {
                $reparti_assegnati_ids[] = $cr['reparto_id'];
            }
        }
    }

    // Se per qualche motivo ha ruoli di coordinatore ma nessun legame esplicito, fallback sul suo reparto_id o su tutti i reparti della sua org se gestisce tutto il plesso
    if (empty($reparti_assegnati_ids) && !empty($org_id_utente)) {
        // Fallback: se è coordinatore ma non ha record specifici, gli mostriamo i reparti della sua organizzazione o verifichiamo
        $res_rep_fallback = supabase_request('reparti', "?organizzazione_id=eq.$org_id_utente&select=id,nome_reparto&order=nome_reparto.asc");
        if (!empty($res_rep_fallback) && is_array($res_rep_fallback) && !isset($res_rep_fallback['code'])) {
            foreach($res_rep_fallback as $rf) {
                $reparti_assegnati_ids[] = $rf['id'];
            }
        }
    }

    // Carichiamo i dettagli dei reparti consentiti al coordinatore
    if (!empty($reparti_assegnati_ids)) {
        $in_list = implode(',', $reparti_assegnati_ids);
        $res_rep_coord = supabase_request('reparti', "?id=in.($in_list)&select=id,nome_reparto&order=nome_reparto.asc");
        if (!empty($res_rep_coord) && is_array($res_rep_coord) && !isset($res_rep_coord['code'])) {
            $lista_reparti_struttura = $res_rep_coord;
        }
    }
}

// Gestione della selezione attuale tramite GET o default
$reparto_selezionato_filtro = $_GET['reparto_filtro'] ?? '';

// Verifica che il reparto selezionato rientri tra quelli autorizzati per questo utente
$id_reparti_consentiti = array_column($lista_reparti_struttura, 'id');
if (empty($reparto_selezionato_filtro) || !in_array($reparto_selezionato_filtro, $id_reparti_consentiti)) {
    if (!empty($lista_reparti_struttura)) {
        $reparto_selezionato_filtro = $lista_reparti_struttura[0]['id'];
    }
}

// Recupero dei nomi descrittivi per l'intestazione
$nome_struttura_corrente = "N/D";
$nome_reparto_corrente = "N/D";

if (!empty($org_id_utente)) {
    $res_org = supabase_request('organizzazioni', "?id=eq.$org_id_utente&select=nome_struttura");
    if (!empty($res_org) && is_array($res_org) && !isset($res_org['code'])) {
        $nome_struttura_corrente = $res_org[0]['nome_struttura'] ?? $res_org[0]['NOME_STRUTTURA'] ?? 'N/D';
    }
}

if (!empty($reparto_selezionato_filtro)) {
    $res_rep = supabase_request('reparti', "?id=eq.$reparto_selezionato_filtro&select=nome_reparto");
    if (!empty($res_rep) && is_array($res_rep) && !isset($res_rep['code'])) {
        $nome_reparto_corrente = $res_rep[0]['nome_reparto'] ?? $res_rep[0]['NOME_REPARTO'] ?? 'N/D';
    }
}

$messaggio = '';
$tipo_alert = '';

// Gestione eliminazione collaboratore
if (isset($_GET['elimina'])) {
    $id_da_eliminare = trim($_GET['elimina']);
    $respDel = supabase_request('staging_utenti', "?id=eq." . urlencode($id_da_eliminare), 'DELETE');
    header("Location: gestione_collaboratori.php?reparto_filtro=" . urlencode($reparto_selezionato_filtro) . "&msg=eliminato");
    exit;
}

// Funzione generazione UUID v4
function generaUuidV4() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// Gestione inserimento o modifica collaboratore
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && ($_POST['azione'] === 'nuovo_collaboratore' || $_POST['azione'] === 'modifica_collaboratore')) {
    $idCollaboratorePost = trim($_POST['id_collaboratore'] ?? '');
    $nomeCollab = trim($_POST['nome'] ?? '');
    $matricolaCollab = trim($_POST['matricola'] ?? '');
    $ruoloCollab = trim($_POST['ruolo'] ?? 'Infermiere');
    $emailCollab = trim($_POST['email'] ?? '');
    $telefonoCollab = trim($_POST['telefono'] ?? '');
    $passwordCollab = trim($_POST['password'] ?? '');
    $squadraCollab = trim($_POST['squadra'] ?? '');
    
    $repartoDestinazione = trim($_POST['reparto_id'] ?? $reparto_selezionato_filtro);
    $organizzazioneDestinazione = $org_id_utente;

    // Controllo sicurezza: il reparto di destinazione deve essere tra quelli consentiti all'utente
    if (!in_array($repartoDestinazione, $id_reparti_consentiti)) {
        $messaggio = "Non hai i permessi per operare su questo reparto.";
        $tipo_alert = "danger";
    } elseif (!empty($nomeCollab) && !empty($emailCollab) && !empty($repartoDestinazione)) {
        $trovatoUtente = supabase_request('staging_utenti', "?email=eq." . urlencode($emailCollab) . "&select=id");

        $idUnico = '';
        if (!empty($trovatoUtente) && is_array($trovatoUtente) && !isset($trovatoUtente['code'])) {
            $idUnico = $trovatoUtente[0]['id'];
        } elseif (!empty($idCollaboratorePost)) {
            $idUnico = $idCollaboratorePost;
        } else {
            $idUnico = generaUuidV4();
        }

        $datiUtente = [
            'id' => $idUnico,
            'nome' => $nomeCollab,
            'matricola' => !empty($matricolaCollab) ? $matricolaCollab : null,
            'email' => $emailCollab,
            'ruolo' => strtolower($ruoloCollab),
            'qualifica' => $ruoloCollab,
            'telefono' => !empty($telefonoCollab) ? $telefonoCollab : null,
            'squadra' => !empty($squadraCollab) ? $squadraCollab : null,
            'organizzazione_id' => $organizzazioneDestinazione,
            'reparto_id' => $repartoDestinazione,
            'is_super_admin' => false
        ];

        if (!empty($passwordCollab)) {
            $datiUtente['password_hash'] = password_hash($passwordCollab, PASSWORD_DEFAULT);
        }

        $checkU = supabase_request('staging_utenti', "?id=eq.$idUnico&select=id");
        $esisteU = (!empty($checkU) && is_array($checkU) && !isset($checkU['code']) && count($checkU) > 0);
        
        $metodoU = $esisteU ? 'PATCH' : 'POST';
        $queryU = $esisteU ? "?id=eq.$idUnico" : "";

        $respU = supabase_request('staging_utenti', $queryU, $metodoU, $datiUtente);

        if (!isset($respU['code'])) {
            $messaggio = "Collaboratore salvato con successo!";
            $tipo_alert = "success";
            $reparto_selezionato_filtro = $repartoDestinazione;
        } else {
            $messaggio = "Errore durante il salvataggio (" . ($respU['code'] ?? 'HTTP') . "): " . ($respU['message'] ?? json_encode($respU));
            $tipo_alert = "danger";
        }
    } else {
        $messaggio = "Compila obbligatoriamente nome, email e seleziona un reparto valido.";
        $tipo_alert = "danger";
    }
}

// Recupero lista collaboratori filtrata per il reparto selezionato
$listaCollaboratori = [];
if (!empty($reparto_selezionato_filtro)) {
    $queryLista = "?reparto_id=eq.$reparto_selezionato_filtro&select=*&order=nome.asc";
    $rispostaLista = supabase_request('staging_utenti', $queryLista, 'GET');
    if (is_array($rispostaLista) && !isset($rispostaLista['code'])) {
        $listaCollaboratori = $rispostaLista;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Gestione Collaboratori - Turni Ospedalieri</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; padding-bottom: 70px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
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
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link active" href="gestione_collaboratori.php">Collaboratori</a></li>
                    <li class="nav-item"><a class="nav-link" href="ferie.php">Ferie e Assenze</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <div>
                <h2 class="mb-1 fw-bold">Area Gestione Personale</h2>
                <h4 class="text-muted mb-0 fs-6">Anagrafica e Credenziali Collaboratori</h4>
            </div>
            <!-- Box informativo Struttura e Selettore Reparto Rapido (Switch) -->
            <div class="card bg-white border px-3 py-2 shadow-sm d-flex flex-row align-items-center gap-3">
                <div>
                    <div class="small text-muted">Struttura: <strong class="text-dark"><?php echo htmlspecialchars($nome_struttura_corrente); ?></strong></div>
                </div>
                <div>
                    <form method="GET" action="gestione_collaboratori.php" class="d-flex align-items-center gap-2 m-0">
                        <label for="reparto_filtro" class="small fw-bold text-secondary mb-0">Reparto:</label>
                        <select name="reparto_filtro" id="reparto_filtro" class="form-select form-select-sm" onchange="this.form.submit()">
                            <?php foreach ($lista_reparti_struttura as $rep) { ?>
                                <option value="<?php echo $rep['id']; ?>" <?php echo ($reparto_selezionato_filtro === $rep['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($rep['nome_reparto']); ?>
                                </option>
                            <?php } ?>
                        </select>
                    </form>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'eliminato'): ?>
            <div class="alert alert-success py-2 small" role="alert">Collaboratore eliminato con successo.</div>
        <?php endif; ?>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> py-2 small" role="alert">
                <?php echo htmlspecialchars($messaggio); ?>
            </div>
        <?php } ?>

        <!-- Form Inserimento / Modifica Collaboratore -->
        <div class="card shadow-sm mb-5 bg-white" id="formCardContainer">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fs-6 fw-bold" id="formTitle">Aggiungi Nuovo Collaboratore</h5>
                <button type="button" class="btn btn-sm btn-light d-none" id="btnAnnullaModifica" onclick="resetFormModifica()">Annulla Modifica</button>
            </div>
            <div class="card-body">
                <form method="POST" action="gestione_collaboratori.php?reparto_filtro=<?php echo urlencode($reparto_selezionato_filtro); ?>" id="collaboratoreForm">
                    <input type="hidden" name="azione" id="formAzione" value="nuovo_collaboratore">
                    <input type="hidden" name="id_collaboratore" id="id_collaboratore" value="">
                    
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label for="reparto_id" class="form-label small fw-bold text-primary">Reparto di Destinazione</label>
                            <select class="form-select border-primary" id="reparto_id" name="reparto_id" required>
                                <?php foreach ($lista_reparti_struttura as $rep) { ?>
                                    <option value="<?php echo $rep['id']; ?>" <?php echo ($reparto_selezionato_filtro === $rep['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($rep['nome_reparto']); ?>
                                    </option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="nome" class="form-label small fw-bold">Nome e Cognome</label>
                            <input type="text" class="form-control" id="nome" name="nome" placeholder="Es. Mario Rossi" required>
                        </div>
                        <div class="col-md-2">
                            <label for="matricola" class="form-label small fw-bold">Matricola</label>
                            <input type="text" class="form-control" id="matricola" name="matricola" placeholder="Es. MAT12345">
                        </div>
                        <div class="col-md-4">
                            <label for="email" class="form-label small fw-bold">Email (Login)</label>
                            <input type="email" class="form-control" id="email" name="email" placeholder="m.rossi@ospedale.it" required>
                        </div>
                        
                        <div class="col-md-2">
                            <label for="telefono" class="form-label small fw-bold">Telefono</label>
                            <input type="text" class="form-control" id="telefono" name="telefono" placeholder="3331234567">
                        </div>
                        <div class="col-md-3">
                            <label for="ruolo" class="form-label small fw-bold">Profilo / Ruolo</label>
                            <select class="form-select" id="ruolo" name="ruolo" required>
                                <option value="collaboratore">Collaboratore</option>
                                <option value="Infermiere">Infermiere</option>
                                <option value="OSS">OSS</option>
                                <option value="Medico">Medico</option>
                                <option value="Tecnico Laboratorio">Tecnico Laboratorio</option>
                                <option value="Tecnico Radiologia">Tecnico Radiologia</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label for="squadra" class="form-label small fw-bold">Squadra</label>
                            <select class="form-select" id="squadra" name="squadra">
                                <option value="">Nessuna / Non assegnata</option>
                                <option value="A">Squadra A</option>
                                <option value="B">Squadra B</option>
                                <option value="C">Squadra C</option>
                                <option value="D">Squadra D</option>
                                <option value="E">Squadra E</option>
                                <option value="Mattiniero">Mattiniero</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="password" class="form-label small fw-bold">Password <small class="text-muted" id="pwdHint">(obbligatoria per nuovi)</small></label>
                            <input type="password" class="form-control" id="password" name="password" placeholder="********">
                        </div>
                        
                        <div class="col-12 text-end mt-3">
                            <button type="submit" class="btn btn-success px-5 fw-bold" id="submitBtn">Salva Collaboratore</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sezione Elenco Collaboratori Censiti -->
        <div class="card shadow-sm mb-5 bg-white">
            <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fs-6 fw-bold">Collaboratori nel reparto selezionato (Totale: <?php echo count($listaCollaboratori); ?>)</h5>
            </div>
            <div class="card-body">
                
                <!-- Vista Mobile -->
                <div class="d-md-none">
                    <?php if (empty($listaCollaboratori)) { ?>
                        <div class="text-center text-muted py-4">Nessun collaboratore censito in questo reparto.</div>
                    <?php } else { ?>
                        <?php foreach ($listaCollaboratori as $collab) { 
                            $nomeCollab = $collab['nome'] ?? $collab['NOME'] ?? 'N/D';
                            $matricolaCollab = $collab['matricola'] ?? $collab['MATRICOLA'] ?? '';
                            $emailCollab = $collab['email'] ?? $collab['EMAIL'] ?? 'N/D';
                            $telCollab = $collab['telefono'] ?? $collab['TELEFONO'] ?? '';
                            $ruoloCollab = $collab['ruolo'] ?? $collab['RUOLO'] ?? 'N/D';
                            $sqCollab = $collab['squadra'] ?? $collab['SQUADRA'] ?? '';
                            $idCollab = $collab['id'] ?? '';
                            $repCollabId = $collab['reparto_id'] ?? '';
                        ?>
                            <div class="card p-3 mb-3 border bg-light">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="fw-bold text-dark mb-1"><?php echo htmlspecialchars($nomeCollab); ?></h6>
                                        <?php if (!empty($matricolaCollab)) { ?>
                                            <span class="badge bg-dark mb-1">Matr: <?php echo htmlspecialchars($matricolaCollab); ?></span>
                                        <?php } ?>
                                        <div><small class="text-muted"><i class="bi bi-envelope"></i> <?php echo htmlspecialchars($emailCollab); ?></small></div>
                                    </div>
                                    <span class="badge bg-info text-dark text-uppercase"><?php echo htmlspecialchars($ruoloCollab); ?></span>
                                </div>
                                <div class="row small text-secondary mt-2 mb-3">
                                    <div class="col-6">
                                        <i class="bi bi-telephone"></i> <?php echo !empty($telCollab) ? htmlspecialchars($telCollab) : 'N/D'; ?>
                                    </div>
                                    <div class="col-6">
                                        <i class="bi bi-people"></i> Squadra: <strong><?php echo !empty($sqCollab) ? htmlspecialchars($sqCollab) : 'N/D'; ?></strong>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end gap-2 border-top pt-2">
                                    <button type="button" class="btn btn-outline-primary btn-sm px-3" onclick="preparaModifica('<?php echo $idCollab; ?>', '<?php echo addslashes($nomeCollab); ?>', '<?php echo addslashes($matricolaCollab); ?>', '<?php echo addslashes($emailCollab); ?>', '<?php echo addslashes($telCollab); ?>', '<?php echo addslashes($ruoloCollab); ?>', '<?php echo addslashes($sqCollab); ?>', '<?php echo $repCollabId; ?>')">
                                        <i class="bi bi-pencil"></i> Modifica
                                    </button>
                                    <a href="gestione_collaboratori.php?reparto_filtro=<?php echo urlencode($reparto_selezionato_filtro); ?>&elimina=<?php echo urlencode($idCollab); ?>" class="btn btn-outline-danger btn-sm px-3" onclick="return confirm('Sei sicuro di voler eliminare questo collaboratore?');">
                                        <i class="bi bi-trash"></i> Elimina
                                    </a>
                                </div>
                            </div>
                        <?php } ?>
                    <?php } ?>
                </div>

                <!-- Vista Desktop -->
                <div class="d-none d-md-block">
                    <div class="table-responsive">
                        <table class="table table-striped align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Nome e Cognome</th>
                                    <th>Matricola</th>
                                    <th>Email</th>
                                    <th>Telefono</th>
                                    <th>Ruolo</th>
                                    <th>Squadra</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($listaCollaboratori)) { ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">Nessun collaboratore censito in questo reparto. Seleziona un altro reparto in alto a destra.</td>
                                    </tr>
                                <?php } else { ?>
                                    <?php foreach ($listaCollaboratori as $collab) { 
                                        $nomeCollab = $collab['nome'] ?? $collab['NOME'] ?? 'N/D';
                                        $matricolaCollab = $collab['matricola'] ?? $collab['MATRICOLA'] ?? '';
                                        $emailCollab = $collab['email'] ?? $collab['EMAIL'] ?? 'N/D';
                                        $telCollab = $collab['telefono'] ?? $collab['TELEFONO'] ?? '';
                                        $ruoloCollab = $collab['ruolo'] ?? $collab['RUOLO'] ?? 'N/D';
                                        $sqCollab = $collab['squadra'] ?? $collab['SQUADRA'] ?? '';
                                        $idCollab = $collab['id'] ?? '';
                                        $repCollabId = $collab['reparto_id'] ?? '';
                                    ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($nomeCollab); ?></strong></td>
                                            <td>
                                                <?php echo !empty($matricolaCollab) ? '<span class="badge bg-light text-dark border">' . htmlspecialchars($matricolaCollab) . '</span>' : '<span class="text-muted">N/D</span>'; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($emailCollab); ?></td>
                                            <td><?php echo !empty($telCollab) ? htmlspecialchars($telCollab) : '<span class="text-muted">N/D</span>'; ?></td>
                                            <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($ruoloCollab); ?></span></td>
                                            <td><?php echo !empty($sqCollab) ? '<span class="badge bg-secondary">' . htmlspecialchars($sqCollab) . '</span>' : '<span class="text-muted">N/D</span>'; ?></td>
                                            <td class="text-end">
                                                <button type="button" class="btn btn-outline-primary btn-sm" title="Modifica" onclick="preparaModifica('<?php echo $idCollab; ?>', '<?php echo addslashes($nomeCollab); ?>', '<?php echo addslashes($matricolaCollab); ?>', '<?php echo addslashes($emailCollab); ?>', '<?php echo addslashes($telCollab); ?>', '<?php echo addslashes($ruoloCollab); ?>', '<?php echo addslashes($sqCollab); ?>', '<?php echo $repCollabId; ?>')">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <a href="gestione_collaboratori.php?reparto_filtro=<?php echo urlencode($reparto_selezionato_filtro); ?>&elimina=<?php echo urlencode($idCollab); ?>" class="btn btn-outline-danger btn-sm" title="Elimina" onclick="return confirm('Sei sicuro di voler eliminare questo collaboratore?');">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php } ?>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function preparaModifica(id, nome, matricola, email, telefono, ruolo, squadra, repartoId) {
            document.getElementById('formAzione').value = 'modifica_collaboratore';
            document.getElementById('id_collaboratore').value = id;
            document.getElementById('nome').value = nome;
            document.getElementById('matricola').value = matricola === 'N/D' ? '' : matricola;
            document.getElementById('email').value = email;
            document.getElementById('telefono').value = telefono === 'N/D' ? '' : telefono;
            
            if (repartoId) {
                document.getElementById('reparto_id').value = repartoId;
            }

            let selectRuolo = document.getElementById('ruolo');
            let trovato = false;
            for (let i = 0; i < selectRuolo.options.length; i++) {
                if (selectRuolo.options[i].value.toLowerCase() === ruolo.toLowerCase()) {
                    selectRuolo.selectedIndex = i;
                    trovato = true;
                    break;
                }
            }
            if (!trovato && ruolo.trim() !== '') {
                let opt = document.createElement('option');
                opt.value = ruolo;
                opt.text = ruolo;
                selectRuolo.add(opt);
                selectRuolo.value = ruolo;
            }

            document.getElementById('squadra').value = squadra;
            document.getElementById('password').required = false;
            document.getElementById('pwdHint').innerText = '(lascia vuoto per non modificare)';
            document.getElementById('formTitle').innerText = 'Modifica Dati Collaboratore: ' + nome;
            document.getElementById('submitBtn').innerText = 'Aggiorna Collaboratore';
            document.getElementById('submitBtn').classList.remove('btn-success');
            document.getElementById('submitBtn').classList.add('btn-primary');
            document.getElementById('btnAnnullaModifica').classList.remove('d-none');

            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function resetFormModifica() {
            document.getElementById('collaboratoreForm').reset();
            document.getElementById('formAzione').value = 'nuovo_collaboratore';
            document.getElementById('id_collaboratore').value = '';
            document.getElementById('password').required = true;
            document.getElementById('pwdHint').innerText = '(obbligatoria per nuovi)';
            document.getElementById('formTitle').innerText = 'Aggiungi Nuovo Collaboratore';
            document.getElementById('submitBtn').innerText = 'Salva Collaboratore';
            document.getElementById('submitBtn').classList.remove('btn-primary');
            document.getElementById('submitBtn').classList.add('btn-success');
            document.getElementById('btnAnnullaModifica').classList.add('d-none');
        }
    </script>
</body>
</html>