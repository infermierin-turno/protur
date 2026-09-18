<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// 1. Estrazione sicura dei dati dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? null;

// Ruoli e permessi
$ruolo_raw = trim($_SESSION['ruolo'] ?? $_SESSION['utente']['ruolo'] ?? '');
$ruolo_lower = strtolower(str_replace([' ', '-'], '_', $ruolo_raw));

$is_super_admin = $_SESSION['is_super_admin'] ?? $_SESSION['utente']['is_super_admin'] ?? false;
if (!$is_super_admin && in_array($ruolo_lower, ['super_admin', 'admin', 'superadmin'])) {
    $is_super_admin = true;
}

$is_capo_personale = in_array($ruolo_lower, ['capo_personale', 'capopersonale']);
$is_coordinatore = ($ruolo_lower === 'coordinatore');

// 2. Recupero di tutti i reparti per popolare il menu a tendina
$reparti_disponibili = [];
$resReparti = supabase_request("reparti?select=id,nome_reparto&order=nome_reparto.asc");
if (is_array($resReparti) && !isset($resReparti['error'])) {
    $reparti_disponibili = $resReparti;
}

// 3. Gestione Reparto Selezionato
$reparto_selezionato = '';
if ($is_super_admin || $is_capo_personale) {
    if (isset($_GET['reparto_id']) && $_GET['reparto_id'] !== '') {
        $reparto_selezionato = $_GET['reparto_id'];
    } elseif (!empty($reparto_id_utente)) {
        $reparto_selezionato = $reparto_id_utente;
    } elseif (!empty($reparti_disponibili)) {
        $reparto_selezionato = $reparti_disponibili[0]['id'];
    }
} else {
    $reparto_selezionato = $reparto_id_utente;
}

// 4. Determinazione del nome del reparto e della struttura da mostrare a schermo
$nome_reparto_selezionato = ($is_super_admin || ($is_capo_personale && $reparto_selezionato === '')) ? "Tutti i Reparti" : "Reparto non assegnato";
$nome_struttura_corrente = "Azienda Sanitaria / Struttura";

if (!empty($org_id_utente)) {
    $res_org = supabase_request('organizzazioni', "?id=eq.$org_id_utente&select=nome_struttura");
    if (!empty($res_org) && is_array($res_org) && !isset($res_org['code'])) {
        $nome_struttura_corrente = $res_org[0]['nome_struttura'] ?? $res_org[0]['NOME_STRUTTURA'] ?? 'Azienda Sanitaria';
    }
}

if ($reparto_selezionato !== '') {
    foreach ($reparti_disponibili as $rep) {
        if ((string)$rep['id'] === (string)$reparto_selezionato) {
            $nome_reparto_selezionato = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
            break;
        }
    }
    if ($nome_reparto_selezionato === "Reparto non assegnato") {
        $resSingleRep = supabase_request("reparti?id=eq." . urlencode($reparto_selezionato) . "&select=nome_reparto");
        if (is_array($resSingleRep) && count($resSingleRep) > 0) {
            $nome_reparto_selezionato = $resSingleRep[0]['nome_reparto'] ?? $resSingleRep[0]['nome'] ?? 'Reparto';
        }
    }
}

// Gestione mese e anno
$mese_selezionato = isset($_GET['mese']) ? intval($_GET['mese']) : intval(date('m'));
$anno_selezionato = isset($_GET['anno']) ? intval($_GET['anno']) : intval(date('Y'));
$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, $mese_selezionato, $anno_selezionato);

// 4.1. Recupero dinamico delle tipologie di turno e dei colori da Supabase
$mappaColoriTurni = [];
$elencoTipologieTurno = [];
$resTipologie = supabase_request("tipologie_turno?select=codice_breve,nome_turno,colore");
if (is_array($resTipologie) && !isset($resTipologie['error'])) {
    $elencoTipologieTurno = $resTipologie;
    foreach ($resTipologie as $t) {
        $codiceBreve = strtoupper(trim($t['codice_breve'] ?? ''));
        $colore = trim($t['colore'] ?? '');
        if (!empty($codiceBreve) && !empty($colore)) {
            $mappaColoriTurni[$codiceBreve] = $colore;
        }
    }
}

// 5. Recupero collaboratori
$mappaUtenti = [];
$resCollab = supabase_request("staging_utenti?select=id,nome,ruolo,qualifica,reparto_id,squadra&order=nome.asc");

if (is_array($resCollab) && !isset($resCollab['error'])) {
    foreach ($resCollab as $c) {
        $repCollabId = $c['reparto_id'] ?? $c['id_reparto'] ?? $c['reparto'] ?? '';
        if ($reparto_selezionato !== '' && (string)$repCollabId !== (string)$reparto_selezionato) {
            continue;
        }
        $c['reparto_id'] = $repCollabId;
        $mappaUtenti[$c['id']] = $c;
    }
}

// 6. Ordinamento collaboratori basato su qualifica/ruolo e squadra
$listaCollaboratoriGrezzi = array_values($mappaUtenti);
usort($listaCollaboratoriGrezzi, function($a, $b) {
    $squadraA = strtoupper(trim($a['squadra'] ?? 'Z'));
    $squadraB = strtoupper(trim($b['squadra'] ?? 'Z'));
    if ($squadraA === '') $squadraA = 'Z';
    if ($squadraB === '') $squadraB = 'Z';

    $cmpSquadra = strcmp($squadraA, $squadraB);
    if ($cmpSquadra !== 0) return $cmpSquadra;

    $ruoloA = strtolower(trim($a['qualifica'] ?? $a['ruolo'] ?? ''));
    $ruoloB = strtolower(trim($b['qualifica'] ?? $b['ruolo'] ?? ''));

    $getPesoRuolo = function($r) {
        if (strpos($r, 'coordinatore') !== false) return 1;
        if (strpos($r, 'infermiere') !== false || strpos($r, 'infermieristica') !== false || strpos($r, 'cpsi') !== false) return 2;
        if (strpos($r, 'oss') !== false || strpos($r, 'operatore socio') !== false) return 3;
        return 4;
    };

    $pesoA = $getPesoRuolo($ruoloA);
    $pesoB = $getPesoRuolo($ruoloB);

    if ($pesoA !== $pesoB) return $pesoA - $pesoB;
    return strcmp($a['nome'] ?? '', $b['nome'] ?? '');
});

$listaCollaboratori = $listaCollaboratoriGrezzi;
$idsUtenti = array_keys($mappaUtenti);

// 8. Recupero pianificazione esistente
$matricePianificazione = []; 
if (!empty($idsUtenti)) {
    $data_inizio_mese = sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato);
    $data_fine_mese = sprintf('%04d-%02d-%d', $anno_selezionato, $mese_selezionato, $giorni_nel_mese);

    $resPian = supabase_request("pianificazione?data_inizio=lte." . $data_fine_mese . "&data_fine=gte." . $data_inizio_mese . "&select=*");
    
    if (is_array($resPian) && !isset($resPian['error'])) {
        foreach ($resPian as $p) {
            $uId = $p['utente_id'] ?? '';
            if (!isset($mappaUtenti[$uId])) continue;
            
            if (($p['stato'] ?? '') === 'cancellato' || empty($p['tipo_evento'])) {
                continue;
            }

            $dInizio = $p['data_inizio'] ?? '';
            $dFine = $p['data_fine'] ?? '';
            $tipoEv = $p['tipo_evento'] ?? 'T';

            if (!empty($uId) && !empty($dInizio)) {
                $giorno_inizio = intval(substr($dInizio, 8, 2));
                $giorno_fine = !empty($dFine) ? intval(substr($dFine, 8, 2)) : $giorno_inizio;
                $mese_evento = intval(substr($dInizio, 5, 2));
                $anno_evento = intval(substr($dInizio, 0, 4));

                if ($mese_evento === $mese_selezionato && $anno_evento === $anno_selezionato) {
                    for ($g = $giorno_inizio; $g <= $giorno_fine; $g++) {
                        if (!isset($matricePianificazione[$uId][$g])) $matricePianificazione[$uId][$g] = [];
                        $partiTurni = explode(',', $tipoEv);
                        foreach ($partiTurni as $pt) {
                            $ptClean = trim($pt);
                            if (!empty($ptClean) && !in_array($ptClean, $matricePianificazione[$uId][$g])) {
                                $matricePianificazione[$uId][$g][] = $ptClean;
                            }
                        }
                    }
                }
            }
        }
    }
}

// 9. Recupero ferie/assenze approvate
$assenzeMese = [];
if (!empty($idsUtenti)) {
    $data_inizio_mese = sprintf('%04d-%02d-01', $anno_selezionato, $mese_selezionato);
    $data_fine_mese = sprintf('%04d-%02d-%d', $anno_selezionato, $mese_selezionato, $giorni_nel_mese);
    $resAss = supabase_request("assenze?stato=eq.approvato&data_inizio=lte." . $data_fine_mese . "&data_fine=gte." . $data_inizio_mese . "&select=*");
    if (is_array($resAss) && !isset($resAss['error'])) {
        foreach ($resAss as $ass) {
            $uIdAss = $ass['utente_id'] ?? '';
            if (!isset($mappaUtenti[$uIdAss])) continue;
            $dInzAss = $ass['data_inizio'] ?? '';
            $dFinAss = $ass['data_fine'] ?? '';
            if (!empty($uIdAss) && !empty($dInzAss) && !empty($dFinAss)) {
                $gInz = max(1, intval(substr($dInzAss, 8, 2)));
                $gFin = min($giorni_nel_mese, intval(substr($dFinAss, 8, 2)));
                for ($gg = $gInz; $gg <= $gFin; $gg++) {
                    $assenzeMese[$uIdAss][$gg] = true;
                }
            }
        }
    }
}

$mesiNomi = [1=>'Gennaio', 2=>'Febbraio', 3=>'Marzo', 4=>'Aprile', 5=>'Maggio', 6=>'Giugno', 7=>'Luglio', 8=>'Agosto', 9=>'Settembre', 10=>'Ottobre', 11=>'Novembre', 12=>'Dicembre'];
$nomeMeseCorrente = $mesiNomi[$mese_selezionato] ?? '';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Pro-Tur - Planner Mensile</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        :root {
            --primary-color: #0f172a;
            --accent-color: #3b82f6;
            --bg-body: #f8fafc;
            --border-color: #cbd5e1;
        }

        body { background-color: var(--bg-body); padding-bottom: 70px; color: #334155; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05); background: #ffffff; }
        
        /* Tabella Planner Video */
        .table-planner { border-collapse: separate; border-spacing: 0; width: 100%; table-layout: fixed; }
        .table-planner th, .table-planner td { text-align: center; vertical-align: middle; font-size: 0.75rem; padding: 4px 2px; border-color: var(--border-color); overflow: hidden; }
        
        .col-collaboratore {
            text-align: left !important;
            padding-left: 8px !important;
            width: 170px !important;
            min-width: 170px !important;
            max-width: 170px !important;
            background-color: #ffffff !important;
            position: sticky;
            left: 0;
            z-index: 4;
            box-shadow: 2px 0 5px rgba(0,0,0,0.03);
            border-right: 2px solid #94a3b8 !important;
        }
        .table-planner thead th.col-collaboratore {
            z-index: 5;
            background-color: var(--primary-color) !important;
        }
        
        .th-giorno {
            width: 32px !important;
            min-width: 32px !important;
            max-width: 32px !important;
            background-color: #334155;
            color: #fff;
            border-bottom: 2px solid #1e293b;
            position: sticky;
            top: 0;
            z-index: 3;
        }
        .th-giorno.weekend { background-color: #0f172a !important; color: #94a3b8 !important; }
        .giorno-num { font-size: 0.75rem; font-weight: 700; line-height: 1.1; }
        .giorno-lettera { font-size: 0.55rem; text-transform: uppercase; opacity: 0.85; font-weight: 600; }
        .weekend-col { background-color: #f1f5f9 !important; }

        .cell-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            min-height: 38px;
        }
        .badge-turno {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 24px;
            height: 20px;
            font-size: 0.65rem;
            font-weight: 700;
            border-radius: 4px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            margin: 0 auto;
        }

        .table-hover-custom tbody tr:hover td.col-collaboratore {
            background-color: #e2e8f0 !important;
        }

        /* Stampa Professionale */
        .print-header-section { display: none; }
        .print-footer-signature { display: none; }

        @media print {
            @page { size: A4 landscape; margin: 8mm; }
            body { background-color: #ffffff !important; padding-bottom: 0 !important; font-size: 8pt !important; color: #000 !important; }
            .navbar, .btn, form, .alert, .card-header, .print-hide { display: none !important; }
            .card { box-shadow: none !important; border: none !important; margin: 0 !important; padding: 0 !important; background: transparent !important; }
            .container-fluid { width: 100% !important; max-width: 100% !important; padding: 0 !important; margin: 0 !important; }
            .table-responsive { overflow: visible !important; max-height: none !important; }
            
            .print-header-section {
                display: block !important;
                margin-bottom: 12px !important;
                border-bottom: 2px solid #0f172a;
                padding-bottom: 8px;
            }

            .table-planner { width: 100% !important; border-collapse: collapse !important; table-layout: fixed !important; }
            .table-planner th, .table-planner td { font-size: 7pt !important; padding: 2px 1px !important; border: 1px solid #94a3b8 !important; color: #000 !important; }
            
            .col-collaboratore { position: static !important; box-shadow: none !important; background-color: #ffffff !important; width: 130px !important; min-width: 130px !important; max-width: 130px !important; text-align: left !important; padding-left: 4px !important; }
            
            .th-giorno { width: auto !important; background-color: #334155 !important; color: #ffffff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .th-giorno .giorno-num, .th-giorno .giorno-lettera { color: #ffffff !important; opacity: 1 !important; }
            .th-giorno.weekend { background-color: #1e293b !important; color: #e2e8f0 !important; }
            
            .badge-turno { width: 18px; height: 16px; font-size: 6pt; border: 1px solid #475569 !important; border-radius: 2px !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; margin: 0 auto; box-shadow: none !important; }
            .cell-container { min-height: 20px; gap: 1px; }
            .weekend-col { background-color: #f1f5f9 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

            .print-footer-signature {
                display: flex !important;
                justify-content: space-between !important;
                align-items: flex-end !important;
                margin-top: 15mm !important;
                font-size: 9pt !important;
                page-break-inside: avoid;
            }
            .signature-box {
                width: 250px;
                text-align: center;
                border-top: 1px solid #000;
                padding-top: 5px;
            }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4 print-hide">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link active" href="riepilogo_ore.php">Ore Totali Mensili</a></li>
                    <li class="nav-item"><a class="nav-link" href="ferie.php">Ferie e Assenze</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <!-- Header Filtri e Azioni -->
        <div class="card shadow-sm mb-3 p-3 print-hide">
            <div class="row align-items-center g-3">
                <div class="col-md-5">
                    <h4 class="mb-0 fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-calendar-range text-primary"></i> 
                        <span><?php echo "$nomeMeseCorrente $anno_selezionato"; ?></span>
                    </h4>
                    <span class="small text-muted">
                        <i class="bi bi-building"></i> Reparto: <strong class="text-dark"><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong>
                    </span>
                </div>
                <div class="col-md-4">
                    <form method="GET" action="planner.php" class="row g-2">
                        <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                            <div class="col-12">
                                <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                    <option value="">Tutti i Reparti</option>
                                    <?php foreach ($reparti_disponibili as $rep) { 
                                        $labelRep = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
                                    ?>
                                        <option value="<?php echo $rep['id']; ?>" <?php echo ($reparto_selezionato !== '' && (string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelRep); ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        <?php } ?>
                        <div class="col-6">
                            <select name="mese" class="form-select form-select-sm">
                                <?php foreach ($mesiNomi as $numM => $strM) { ?>
                                    <option value="<?php echo $numM; ?>" <?php echo ($numM === $mese_selezionato) ? 'selected' : ''; ?>><?php echo $strM; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <select name="anno" class="form-select form-select-sm">
                                <?php for ($a = 2024; $a <= 2030; $a++) { ?>
                                    <option value="<?php echo $a; ?>" <?php echo ($a === $anno_selezionato) ? 'selected' : ''; ?>><?php echo $a; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-3">
                            <button type="submit" class="btn btn-primary btn-sm w-100 fw-semibold"><i class="bi bi-filter"></i> Filtra</button>
                        </div>
                    </form>
                </div>
                <div class="col-md-3 text-end">
                    <button onclick="window.print();" class="btn btn-secondary btn-sm fw-bold shadow-sm"><i class="bi bi-printer"></i> Stampa Mese Intero</button>
                </div>
            </div>
        </div>

        <!-- Legenda Turni Dinamica -->
        <div class="card shadow-sm mb-3 p-2 px-3 print-hide">
            <div class="d-flex align-items-center flex-wrap gap-3">
                <span class="small fw-bold text-muted"><i class="bi bi-info-circle"></i> Legenda:</span>
                <?php foreach ($elencoTipologieTurno as $tLeg) { 
                    $cBreve = strtoupper(trim($tLeg['codice_breve'] ?? ''));
                    $nTurno = $tLeg['nome_turno'] ?? $cBreve;
                    $colHex = $tLeg['colore'] ?? '#64748b';
                ?>
                    <div class="d-flex align-items-center gap-1">
                        <span class="badge-turno" style="background-color: <?php echo htmlspecialchars($colHex); ?>; color: #fff;"><?php echo htmlspecialchars($cBreve); ?></span>
                        <span class="small text-secondary"><?php echo htmlspecialchars($nTurno); ?></span>
                    </div>
                <?php } ?>
                <div class="d-flex align-items-center gap-1">
                    <span class="badge-turno bg-secondary text-white">F</span>
                    <span class="small text-secondary">Ferie / Assenza</span>
                </div>
            </div>
        </div>

        <!-- CONTENITORE PRINCIPALE PLANNER INTERO MESE -->
        <div class="card shadow-sm mb-4">
            <div class="card-body p-0">
                
                <!-- Intestazione formale esclusiva per la Stampa -->
                <div class="print-header-section">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h5 class="fw-bold mb-1" style="font-size: 11pt; color: #0f172a;"><?php echo htmlspecialchars($nome_struttura_corrente); ?></h5>
                            <div class="text-muted" style="font-size: 9pt;">Unità Operativa / Reparto: <strong><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong></div>
                        </div>
                        <div class="text-end">
                            <h4 class="fw-bold mb-0" style="font-size: 13pt; color: #0f172a;">PIANIFICAZIONE TURNI MENSILE</h4>
                            <div class="text-uppercase fw-semibold text-primary" style="font-size: 10pt;"><?php echo "$nomeMeseCorrente $anno_selezionato"; ?></div>
                        </div>
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 70vh;">
                    <table class="table table-bordered table-hover-custom table-planner mb-0">
                        <thead>
                            <tr>
                                <th class="col-collaboratore">Collaboratore</th>
                                <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                    $timestampGiorno = mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato);
                                    $numGiornoSettimana = date('N', $timestampGiorno); // 1 (Lun) - 7 (Dom)
                                    $letteraGiorno = ['1'=>'L', '2'=>'M', '3'=>'M', '4'=>'G', '5'=>'V', '6'=>'S', '7'=>'D'][$numGiornoSettimana];
                                    $isWeekend = ($numGiornoSettimana >= 6);
                                ?>
                                    <th class="th-giorno <?php echo $isWeekend ? 'weekend' : ''; ?>">
                                        <div class="giorno-num"><?php echo $g; ?></div>
                                        <div class="giorno-lettera"><?php echo $letteraGiorno; ?></div>
                                    </th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listaCollaboratori)) { ?>
                                <tr>
                                    <td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="text-center py-4 text-muted">Nessun collaboratore trovato per i filtri selezionati.</td>
                                </tr>
                            <?php } else { 
                                $squadraCorrente = null;
                                foreach ($listaCollaboratori as $collab) {
                                    $uId = $collab['id'];
                                    $nomeCollab = $collab['nome'] ?? 'Utente';
                                    $qualificaCollab = trim($collab['qualifica'] ?? '') !== '' ? $collab['qualifica'] : ($collab['ruolo'] ?? '');
                                    $squadraCollab = strtoupper(trim($collab['squadra'] ?? ''));

                                    if ($squadraCollab !== $squadraCorrente) {
                                        $squadraCorrente = $squadraCollab;
                            ?>
                                        <tr class="table-secondary print-hide">
                                            <td colspan="<?php echo $giorni_nel_mese + 1; ?>" class="fw-bold py-1 px-3 small text-uppercase text-dark bg-light">
                                                <i class="bi bi-people-fill me-1"></i> Squadra: <?php echo !empty($squadraCorrente) ? htmlspecialchars($squadraCorrente) : 'Non Assegnata'; ?>
                                            </td>
                                        </tr>
                            <?php 
                                    }
                            ?>
                                    <tr>
                                        <td class="col-collaboratore">
                                            <div class="fw-bold text-dark text-truncate" style="font-size: 0.78rem;" title="<?php echo htmlspecialchars($nomeCollab); ?>">
                                                <?php echo htmlspecialchars($nomeCollab); ?>
                                            </div>
                                            <div class="text-muted text-truncate" style="font-size: 0.62rem;">
                                                <?php echo htmlspecialchars($qualificaCollab); ?>
                                                <?php if (!empty($squadraCollab)) { ?>
                                                    <span class="badge bg-light text-dark border ms-1" style="font-size: 0.55rem;"><?php echo htmlspecialchars($squadraCollab); ?></span>
                                                <?php } ?>
                                            </div>
                                        </td>
                                        <?php for ($g = 1; $g <= $giorni_nel_mese; $g++) {
                                            $timestampGiorno = mktime(0, 0, 0, $mese_selezionato, $g, $anno_selezionato);
                                            $numGiornoSettimana = date('N', $timestampGiorno);
                                            $isWeekend = ($numGiornoSettimana >= 6);
                                            
                                            $haAssenza = isset($assenzeMese[$uId][$g]);
                                            $turniCella = $matricePianificazione[$uId][$g] ?? [];
                                        ?>
                                            <td class="<?php echo $isWeekend ? 'weekend-col' : ''; ?>">
                                                <div class="cell-container">
                                                    <?php if ($haAssenza) { ?>
                                                        <span class="badge-turno bg-secondary text-white" title="Ferie / Assenza Approvata">F</span>
                                                    <?php } elseif (!empty($turniCella)) { ?>
                                                        <?php foreach ($turniCella as $tSingolo) {
                                                            $tClean = strtoupper(trim($tSingolo));
                                                            $coloreBadge = $mappaColoriTurni[$tClean] ?? '#3b82f6';
                                                        ?>
                                                            <span class="badge-turno" style="background-color: <?php echo htmlspecialchars($coloreBadge); ?>; color: #fff;" title="<?php echo htmlspecialchars($tClean); ?>"><?php echo htmlspecialchars($tClean); ?></span>
                                                        <?php } ?>
                                                    <?php } else { ?>
                                                        <span class="text-muted opacity-25" style="font-size: 0.6rem;">·</span>
                                                    <?php } ?>
                                                </div>
                                            </td>
                                        <?php } ?>
                                    </tr>
                                <?php } ?>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>

                <!-- Sezione Firma del Coordinatore (Visibile solo in stampa) -->
                <div class="print-footer-signature">
                    <div>
                        <div class="text-muted" style="font-size: 8pt;">Documento generato automaticamente tramite sistema PRO-TUR</div>
                        <div style="font-size: 8pt;">Data di stampa: <?php echo date('d/m/Y'); ?></div>
                    </div>
                    <div class="signature-box">
                        <div class="fw-bold" style="font-size: 9pt;">Il Coordinatore Sanitario</div>
                        <div class="text-muted" style="font-size: 7.5pt; margin-top: 25px;">(Firma e Timbro)</div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>