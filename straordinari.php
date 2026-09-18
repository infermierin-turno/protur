<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

function supabase_straordinari_request($endpoint, $method = 'GET', $data = null) {
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $ch = curl_init($url);
    
    $headers = [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept-Profile: public',
        'Content-Profile: public'
    ];

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $headers[] = 'Prefer: return=representation';
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $headers[] = 'Prefer: return=representation';
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return $method === 'GET' ? [] : false;
}

$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? null;

$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? false;
if (!$is_super_admin && ($ruolo_lower === 'super_admin' || $ruolo_lower === 'admin' || $ruolo_lower === 'superadmin')) {
    $is_super_admin = true;
}
$is_capo_personale = ($ruolo_lower === 'capo_personale' || $ruolo_lower === 'capopersonale');

if (empty($reparto_id_utente) && !empty($utente_id)) {
    $datiUserCurr = supabase_straordinari_request("staging_utenti?id=eq.$utente_id&select=reparto_id,organizzazione_id,matricola");
    if (!empty($datiUserCurr) && is_array($datiUserCurr)) {
        $reparto_id_utente = !empty($datiUserCurr[0]['reparto_id']) ? $datiUserCurr[0]['reparto_id'] : null;
        if (empty($org_id_utente)) {
            $org_id_utente = !empty($datiUserCurr[0]['organizzazione_id']) ? $datiUserCurr[0]['organizzazione_id'] : null;
        }
    }
}

$mappaReparti = [];
$resReparti = supabase_straordinari_request("reparti?select=id,nome_reparto");
if (is_array($resReparti)) {
    foreach ($resReparti as $r) {
        $mappaReparti[$r['id']] = $r['nome_reparto'];
    }
}

$messaggio = '';
$tipo_alert = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'nuovo_straordinario') {
    $utente_selezionato = trim($_POST['utente_id'] ?? '');
    $data_inizio = trim($_POST['data_inizio'] ?? '');
    $ora_inizio = trim($_POST['ora_inizio'] ?? '');
    $data_fine = trim($_POST['data_fine'] ?? '');
    $ora_fine = trim($_POST['ora_fine'] ?? '');
    $turno_riferimento = trim($_POST['turno_riferimento'] ?? '');
    $motivazione = trim($_POST['motivazione'] ?? '');

    if (!empty($utente_selezionato) && !empty($data_inizio) && !empty($data_fine) && !empty($ora_inizio) && !empty($ora_fine)) {
        $t1 = strtotime("$data_inizio $ora_inizio");
        $t2 = strtotime("$data_fine $ora_fine");
        $diff_ore = ($t2 - $t1) / 3600;
        if ($diff_ore < 0) { $diff_ore = 0; }

        $annoCorrente = date('y'); 
        $ultimiProt = supabase_straordinari_request("straordinari?select=numero_protocollo&order=created_at.desc&limit=20");
        $prossimoNum = 1;
        
        if (is_array($ultimiProt)) {
            foreach ($ultimiProt as $p) {
                $protStr = $p['numero_protocollo'] ?? '';
                if (preg_match('/^(\d+)\/' . $annoCorrente . '$/', $protStr, $matches)) {
                    $numTrovato = intval($matches[1]);
                    if ($numTrovato >= $prossimoNum) {
                        $prossimoNum = $numTrovato + 1;
                        break;
                    }
                }
            }
        }
        $numero_protocollo_automatico = $prossimoNum . '/' . $annoCorrente;

        $datiInsert = [
            'organizzazione_id' => !empty($org_id_utente) ? $org_id_utente : null,
            'reparto_id' => !empty($reparto_id_utente) ? $reparto_id_utente : null,
            'utente_id' => $utente_selezionato,
            'data_straordinario' => $data_inizio,
            'data_fine_straordinario' => $data_fine,
            'turno_riferimento' => $turno_riferimento,
            'ora_inizio' => $ora_inizio . ':00',
            'ora_fine' => $ora_fine . ':00',
            'totale_ore' => round($diff_ore, 2),
            'motivazione' => $motivazione,
            'numero_protocollo' => $numero_protocollo_automatico,
            'stato' => 'Approvato'
        ];

        $res = supabase_straordinari_request("straordinari", 'POST', $datiInsert);
        if ($res !== false) {
            $messaggio = "Modulo straordinario registrato con successo! Protocollo generato: $numero_protocollo_automatico";
            $tipo_alert = "success";
        } else {
            $messaggio = "Errore durante il salvataggio dello straordinario.";
            $tipo_alert = "danger";
        }
    } else {
        $messaggio = "Compila tutti i campi obbligatori di data e ora.";
        $tipo_alert = "warning";
    }
}

$listaCollaboratori = [];
$queryCollab = "staging_utenti?select=id,nome,ruolo,qualifica,reparto_id,matricola&order=nome.asc";
if (!$is_super_admin && !$is_capo_personale && !empty($reparto_id_utente)) {
    $queryCollab = "staging_utenti?reparto_id=eq." . urlencode($reparto_id_utente) . "&select=id,nome,ruolo,qualifica,reparto_id,matricola&order=nome.asc";
}
$resCollab = supabase_straordinari_request($queryCollab);
if (is_array($resCollab)) {
    foreach ($resCollab as $c) {
        $listaCollaboratori[$c['id']] = $c;
    }
}

$idsUtentiFiltro = array_keys($listaCollaboratori);
$queryStraordinari = "straordinari?select=*&order=data_straordinario.desc";
if (!$is_super_admin && !$is_capo_personale && !empty($idsUtentiFiltro)) {
    $queryStraordinari = "straordinari?utente_id=in.(" . implode(',', $idsUtentiFiltro) . ")&select=*&order=data_straordinario.desc";
}
$elencoStraordinari = supabase_straordinari_request($queryStraordinari);
if (!is_array($elencoStraordinari)) { $elencoStraordinari = []; }

// Elaborazione statistiche per dipendente e regime
$statisticheDipendenti = [];
foreach ($elencoStraordinari as $st) {
    $uid = $st['utente_id'];
    if (!isset($statisticheDipendenti[$uid])) {
        $statisticheDipendenti[$uid] = [
            'totale_ore' => 0,
            'ore_notturne' => 0,
            'ore_festive' => 0,
            'ore_reperibilita' => 0,
            'ore_altre' => 0
        ];
    }
    
    $ore = floatval($st['totale_ore'] ?? 0);
    $turno = strtoupper(trim($st['turno_riferimento'] ?? ''));
    $motivazione_lower = mb_strtolower($st['motivazione'] ?? '');
    
    $statisticheDipendenti[$uid]['totale_ore'] += $ore;

    if (str_contains($turno, 'REP') || str_contains($motivazione_lower, 'reperibilit')) {
        $statisticheDipendenti[$uid]['ore_reperibilita'] += $ore;
    } elseif (str_contains($turno, 'N') || str_contains($motivazione_lower, 'notturn')) {
        $statisticheDipendenti[$uid]['ore_notturne'] += $ore;
    } elseif (str_contains($turno, 'F') || str_contains($motivazione_lower, 'festiv')) {
        $statisticheDipendenti[$uid]['ore_festive'] += $ore;
    } else {
        $statisticheDipendenti[$uid]['ore_altre'] += $ore;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Straordinari - PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f4f7f6; padding-bottom: 70px; }
        .card { border: none; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm;
            }
            body, html {
                width: 100% !important;
                height: 100% !important;
                background-color: #fff !important;
                margin: 0 !important;
                padding: 0 !important;
            }
            .no-print { display: none !important; }
            .print-container {
                display: block !important;
                width: 100% !important;
                height: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }
            .modulo-asl {
                width: 100% !important;
                height: 100% !important;
                max-width: none !important;
                border: 2px solid #000 !important;
                padding: 15px !important;
                font-size: 15px !important;
            }
        }

        .modulo-asl {
            font-family: 'Times New Roman', Times, serif;
            background: #fff;
            border: 2px solid #000;
            padding: 35px;
            max-width: 850px;
            margin: 0 auto;
            color: #000;
            font-size: 1.05rem;
        }
        .asl-header {
            position: relative;
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 12px;
            margin-bottom: 20px;
            min-height: 95px;
        }
        .asl-logo {
            position: absolute;
            left: 0;
            top: 0;
            width: 80px;
            height: auto;
        }
        .asl-header-text {
            text-align: center;
            line-height: 1.3;
            padding-left: 90px;
            padding-right: 90px;
        }
        /* Stili per l'autocompletamento nel form */
        #suggerimenti-box {
            position: absolute;
            z-index: 1000;
            width: 100%;
            max-height: 180px;
            overflow-y: auto;
            background: #fff;
            border: 1px solid #ced4da;
            border-top: none;
            border-radius: 0 0 0.375rem 0.375rem;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            display: none;
        }
        .suggerimento-item {
            padding: 8px 12px;
            cursor: pointer;
            font-size: 0.9rem;
            border-bottom: 1px solid #f1f1f1;
        }
        .suggerimento-item:hover {
            background-color: #e9ecef;
        }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 no-print">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php">PRO-TUR | Gestione Turni</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="turni.php">Turni</a></li>
                    <li class="nav-item"><a class="nav-link active" href="straordinari.php">Straordinari</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container px-4 no-print">
        <h2 class="mb-3 fw-bold"><i class="bi bi-file-earmark-text-fill"></i> Gestione e Modulo Straordinari</h2>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> py-2 small" role="alert">
                <?php echo htmlspecialchars($messaggio); ?>
            </div>
        <?php } ?>

        <div class="row mb-4">
            <div class="col-lg-5 mb-3 mb-lg-0">
                <div class="card h-100 bg-white">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-plus-circle"></i> Compila Modulo Straordinario</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="straordinari.php" id="formStraordinario">
                            <input type="hidden" name="azione" value="nuovo_straordinario">
                            <input type="hidden" name="utente_id" id="utente_id_hidden" required>
                            
                            <div class="mb-2 position-relative">
                                <label class="form-label small fw-bold">Cerca Operatore (Nome e Cognome)</label>
                                <input type="text" class="form-control form-control-sm" id="inputRicercaOperatore" placeholder="Digita nome o cognome..." autocomplete="off" required>
                                <div id="suggerimenti-box"></div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small fw-bold">Dal Giorno (Inizio)</label>
                                    <input type="date" class="form-control form-control-sm" name="data_inizio" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small fw-bold">Dalle Ore</label>
                                    <input type="time" class="form-control form-control-sm" name="ora_inizio" required>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small fw-bold">Al Giorno (Fine)</label>
                                    <input type="date" class="form-control form-control-sm" name="data_fine" value="<?php echo date('Y-m-d'); ?>" required>
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="form-label small fw-bold">Alle Ore</label>
                                    <input type="time" class="form-control form-control-sm" name="ora_fine" required>
                                </div>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small fw-bold">Turno (es. M, P, N, REP)</label>
                                <input type="text" class="form-control form-control-sm" name="turno_riferimento" placeholder="es. N o REP" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small fw-bold">Motivazione / Causale</label>
                                <input type="text" class="form-control form-control-sm" name="motivazione" placeholder="es. GESTIONE SERVIZI ESTERNI / REPERIBILITA" required>
                            </div>

                            <div class="text-end">
                                <button type="submit" class="btn btn-dark btn-sm fw-bold">Salva e Genera Modulo</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card h-100 bg-white">
                    <div class="card-header bg-white py-3">
                        <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-list-check"></i> Storico Moduli Straordinario (Reparto)</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive" style="max-height: 400px;">
                            <table class="table table-striped table-hover mb-0 small align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Prot.</th>
                                        <th>Inizio</th>
                                        <th>Fine</th>
                                        <th>Operatore</th>
                                        <th>Orario</th>
                                        <th class="text-end">Azione</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($elencoStraordinari)): ?>
                                        <?php foreach ($elencoStraordinari as $st): 
                                            $uInfo = $listaCollaboratori[$st['utente_id']] ?? ['nome' => 'Operatore Sconosciuto', 'ruolo' => 'CPSI', 'qualifica' => 'CPSI', 'reparto_id' => $st['reparto_id'], 'matricola' => 'N/D'];
                                            $dataFineVal = $st['data_fine_straordinario'] ?? $st['data_straordinario'];
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($st['numero_protocollo'] ?? '---'); ?></strong></td>
                                                <td><?php echo date('d/m/Y', strtotime($st['data_straordinario'])); ?></td>
                                                <td><?php echo date('d/m/Y', strtotime($dataFineVal)); ?></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($uInfo['nome']); ?></strong><br>
                                                    <span class="text-muted" style="font-size: 11px;">Mat: <?php echo htmlspecialchars((!empty($uInfo['matricola'])) ? $uInfo['matricola'] : 'N/D'); ?></span>
                                                </td>
                                                <td><?php echo substr($st['ora_inizio'], 0, 5); ?> - <?php echo substr($st['ora_fine'], 0, 5); ?></td>
                                                <td class="text-end">
                                                    <button class="btn btn-outline-primary btn-sm py-0 px-2" onclick="apriStampaModulo(<?php echo htmlspecialchars(json_encode($st), ENT_QUOTES); ?>, <?php echo htmlspecialchars(json_encode($uInfo), ENT_QUOTES); ?>)">
                                                        <i class="bi bi-printer"></i> Stampa
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">Nessun modulo straordinario registrato per questo reparto.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIEPILOGO ORE PER DIPENDENTE E REGIME CON FILTRO DI RICERCA -->
        <div class="row mb-5">
            <div class="col-12">
                <div class="card bg-white">
                    <div class="card-header bg-white py-3 d-flex flex-column flex-md-row justify-content-between align-items-center gap-2">
                        <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-bar-chart-fill"></i> Riepilogo straordinario per dipendente.</h5>
                        <!-- Barra di ricerca rapida per il riepilogo ore -->
                        <div style="width: 300px;">
                            <input type="text" class="form-control form-control-sm" id="filtroTabellaOre" placeholder="Filtra per nome dipendente..." autocomplete="off">
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-striped mb-0 small align-middle" id="tabellaRiepilogoOre">
                                <thead class="table-secondary">
                                    <tr>
                                        <th>Dipendente</th>
                                        <th>Matricola</th>
                                        <th class="text-center">Totale Ore</th>
                                        <th class="text-center">Notturne (N)</th>
                                        <th class="text-center">Festive</th>
                                        <th class="text-center">Reperibilità</th>
                                        <th class="text-center">Altre / Diurne</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($statisticheDipendenti)): ?>
                                        <?php foreach ($statisticheDipendenti as $uid => $datiStats): 
                                            $uInfoStat = $listaCollaboratori[$uid] ?? ['nome' => 'Operatore Sconosciuto', 'matricola' => 'N/D'];
                                        ?>
                                            <tr class="riga-dipendente-ore">
                                                <td class="nome-dipendente-testo"><strong><?php echo htmlspecialchars($uInfoStat['nome']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($uInfoStat['matricola'] ?? 'N/D'); ?></td>
                                                <td class="text-center fw-bold text-dark"><?php echo number_format($datiStats['totale_ore'], 2); ?> h</td>
                                                <td class="text-center text-primary"><?php echo number_format($datiStats['ore_notturne'], 2); ?> h</td>
                                                <td class="text-center text-success"><?php echo number_format($datiStats['ore_festive'], 2); ?> h</td>
                                                <td class="text-center text-warning fw-bold"><?php echo number_format($datiStats['ore_reperibilita'], 2); ?> h</td>
                                                <td class="text-center text-muted"><?php echo number_format($datiStats['ore_altre'], 2); ?> h</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-3">Nessun dato statistico disponibile.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="wrapperModuloStampa" class="print-container my-4" style="display: none;">
        <div class="text-end mb-3 no-print container">
            <button class="btn btn-secondary btn-sm" onclick="chiudiStampa()"><i class="bi bi-arrow-left"></i> Torna alla Gestione</button>
            <button class="btn btn-primary btn-sm fw-bold" onclick="window.print()"><i class="bi bi-printer"></i> Stampa Modulo Ufficiale</button>
        </div>

        <div class="modulo-asl" id="contenutoModuloStampa">
            <div class="asl-header">
                <img src="img/logo_asl.jpg" alt="Logo ASL" class="asl-logo" onerror="this.onerror=null; this.src='data:image/svg+xml;utf8,<svg xmlns=\'http://www.w3.org/2000/svg\' width=\'80\' height=\'80\'><rect width=\'100%\' height=\'100%\' fill=\'%23e2e8f0\'/><text x=\'50%\' y=\'50%\' font-size=\'11\' text-anchor=\'middle\' dominant-baseline=\'middle\' fill=\'%2364748b\' font-family=\'sans-serif\'>ASL</text></svg>';">
                <div class="asl-header-text">
                    <div style="font-size: 1.15rem; font-weight: bold; letter-spacing: 0.5px;">AZIENDA SANITARIA LOCALE</div>
                    <div style="font-size: 1.4rem; font-weight: bold; letter-spacing: 0.5px; margin-top: 2px;">NAPOLI 1 CENTRO</div>
                    <div style="font-size: 0.85rem; margin-top: 4px;">P.O. dei Pellegrini</div>
                    <div style="font-size: 0.82rem;">via Comunale del Principe n°13/A - 80145 – Napoli – C.F. 06328131211</div>
                    <div style="font-size: 0.82rem;">tel. 081-254.44.83, email: protocollogenerale@aslnapoli1centro.it, PEC: aslnapoli1centro@pec.aslna1centro.it</div>
                    <div style="font-size: 0.99rem; line-height: 1.35; margin-top: 4px;">
                        <b>Presidio Ospedaliero dei "Pellegrini"<br>
                        via Portamedina alla Pignasecca n°41 - 80134 - Napoli<br></b>
                    </div>
                </div>
            </div>

            <div class="row mb-3" style="font-size: 0.95rem;">
                <div class="col-6"></div>
                <div class="col-6 text-end">
                    <strong>Al Direttore Sanitario</strong><br>
                    <strong>A Ufficio Infermieristico</strong>
                    <div class="mt-2">Prot nº: <span id="lbl_protocollo">---</span></div>
                    <div>Redatto il: <span id="lbl_data_redazione">---</span></div>
                </div>
            </div>

            <h5 class="text-center fw-bold my-4" style="font-size: 1.25rem; text-decoration: underline;">Oggetto: AUTORIZZAZIONE ORARIO IN REGIME DI STRAORDINARIO</h5>

            <div style="font-size: 1.05rem; line-height: 1.8;">
                <p>Si autorizza il Sig./ra <strong id="lbl_nome_dipendente">---</strong> matricola: <strong id="lbl_matricola">---</strong></p>
                <p>In servizio presso il reparto <strong id="lbl_reparto">---</strong> con Profilo Professionale <strong id="lbl_profilo">CPSI</strong></p>
                
                <p class="my-3">
                    Ad effettuare lavoro straordinario in regime di turno <strong>[ <span id="lbl_turno" class="text-uppercase fw-bold">N</span> ]</strong> nel reparto di competenza, secondo il seguente prospetto:
                </p>

                <table class="table table-bordered text-center my-3" style="font-size: 1rem;">
                    <thead class="table-light">
                        <tr>
                            <th>Il giorno</th>
                            <th>Dalle ore</th>
                            <th>Alla data</th>
                            <th>Alle ore</th>
                            <th>Totale ore</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><span id="lbl_giorno_1">---</span></td>
                            <td><span id="lbl_ora_inizio">---</span></td>
                            <td><span id="lbl_giorno_2">---</span></td>
                            <td><span id="lbl_ora_fine">---</span></td>
                            <td><strong><span id="lbl_tot_ore">---</span> h</strong></td>
                        </tr>
                    </tbody>
                </table>

                <div class="mb-4 p-3 border border-dark rounded-0 bg-white">
                    <strong>Motivazione:</strong><br>
                    <div class="mt-1" id="lbl_motivazione" style="min-height: 45px; font-weight: bold;">---</div>
                </div>
            </div>

            <div class="row mt-5" style="font-size: 1rem;">
                <div class="col-6">
                    <p>Data <span id="lbl_data_firma">---</span></p>
                    <br><br>
                    <p style="border-top: 1px solid #000; display: inline-block; width: 85%;">Firma del dipendente per accettazione</p>
                </div>
                <div class="col-6 text-end">
                    <br><br>
                    <p style="border-top: 1px solid #000; display: inline-block; width: 85%;">TIMBRO E FIRMA<br>Il Coordinatore / Il Direttore U.O.C.</p>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const mappaRepartiJs = <?php echo json_encode($mappaReparti); ?>;
        const listaCollaboratoriJs = <?php echo json_encode(array_values($listaCollaboratori)); ?>;

        // Autocompletamento per inserire il modulo straordinario
        const inputRicerca = document.getElementById('inputRicercaOperatore');
        const boxSuggerimenti = document.getElementById('suggerimenti-box');
        const inputUtenteIdHidden = document.getElementById('utente_id_hidden');

        inputRicerca.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            boxSuggerimenti.innerHTML = '';
            inputUtenteIdHidden.value = '';

            if (query.length === 0) {
                boxSuggerimenti.style.display = 'none';
                return;
            }

            const risultati = listaCollaboratoriJs.filter(c => c.nome && c.nome.toLowerCase().includes(query));

            if (risultati.length > 0) {
                boxSuggerimenti.style.display = 'block';
                risultati.forEach(c => {
                    const div = document.createElement('div');
                    div.classList.add('suggerimento-item');
                    div.innerHTML = `<strong>${c.nome}</strong> <span class="text-muted">(Mat: ${c.matricola || 'N/D'})</span>`;
                    div.addEventListener('click', function() {
                        inputRicerca.value = c.nome;
                        inputUtenteIdHidden.value = c.id;
                        boxSuggerimenti.style.display = 'none';
                    });
                    boxSuggerimenti.appendChild(div);
                });
            } else {
                boxSuggerimenti.style.display = 'none';
            }
        });

        document.addEventListener('click', function(e) {
            if (!inputRicerca.contains(e.target) && !boxSuggerimenti.contains(e.target)) {
                boxSuggerimenti.style.display = 'none';
            }
        });

        document.getElementById('formStraordinario').addEventListener('submit', function(e) {
            if (!inputUtenteIdHidden.value) {
                e.preventDefault();
                alert("Per favore, seleziona un operatore valido dai suggerimenti della ricerca.");
                inputRicerca.focus();
            }
        });

        // Filtro di ricerca in tempo reale per la tabella del Riepilogo Ore
        const inputFiltroOre = document.getElementById('filtroTabellaOre');
        if (inputFiltroOre) {
            inputFiltroOre.addEventListener('input', function() {
                const valFiltro = this.value.toLowerCase().trim();
                const righe = document.querySelectorAll('.riga-dipendente-ore');

                righe.forEach(riga => {
                    const testoNome = riga.querySelector('.nome-dipendente-testo').textContent.toLowerCase();
                    if (testoNome.includes(valFiltro)) {
                        riga.style.display = '';
                    } else {
                        riga.style.display = 'none';
                    }
                });
            });
        }

        function apriStampaModulo(st, uInfo) {
            document.querySelector('.container.px-4').style.display = 'none';
            document.getElementById('wrapperModuloStampa').style.display = 'block';

            document.getElementById('lbl_protocollo').innerText = st.numero_protocollo || '---';
            document.getElementById('lbl_data_redazione').innerText = st.created_at ? new Date(st.created_at).toLocaleString() : new Date().toLocaleString();
            document.getElementById('lbl_nome_dipendente').innerText = uInfo.nome || 'Operatore';
            
            let matricolaVal = 'N/D';
            if (uInfo.matricola !== null && uInfo.matricola !== undefined && String(uInfo.matricola).trim() !== '') {
                matricolaVal = uInfo.matricola;
            }
            document.getElementById('lbl_matricola').innerText = matricolaVal;
            
            let repId = uInfo.reparto_id || st.reparto_id;
            let nomeRepartoTrovato = mappaRepartiJs[repId] || 'SERVIZI INTRAOSPEDALIERI';
            document.getElementById('lbl_reparto').innerText = nomeRepartoTrovato;

            let qualificaFormale = uInfo.qualifica && uInfo.qualifica.trim() !== '' ? uInfo.qualifica : (uInfo.ruolo || 'CPSI');
            document.getElementById('lbl_profilo').innerText = qualificaFormale;
            
            let dataInizioStr = st.data_straordinario;
            let dataFineStr = st.data_fine_straordinario || st.data_straordinario;

            document.getElementById('lbl_turno').innerText = st.turno_riferimento || 'N';
            document.getElementById('lbl_giorno_1').innerText = dataInizioStr;
            document.getElementById('lbl_giorno_2').innerText = dataFineStr;
            document.getElementById('lbl_ora_inizio').innerText = st.ora_inizio ? st.ora_inizio.substring(0, 5) : '';
            document.getElementById('lbl_ora_fine').innerText = st.ora_fine ? st.ora_fine.substring(0, 5) : '';
            document.getElementById('lbl_tot_ore').innerText = st.totale_ore || '1.00';
            document.getElementById('lbl_motivazione').innerText = st.motivazione || '';
            document.getElementById('lbl_data_firma').innerText = dataInizioStr;

            window.scrollTo(0, 0);
        }

        function chiudiStampa() {
            document.getElementById('wrapperModuloStampa').style.display = 'none';
            document.querySelector('.container.px-4').style.display = 'block';
        }
    </script>
</body>
</html>