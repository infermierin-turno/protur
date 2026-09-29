<?php
session_start();
if (!isset($_SESSION['utente'])) { header("Location: index.php"); exit; }
require_once 'config.php';

// Estrazione sicura dell'ID utente, organizzazione e reparto dalla sessione
$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;
$org_id_utente = $_SESSION['organizzazione_id'] ?? $_SESSION['utente']['organizzazione_id'] ?? $_SESSION['utente']['ORGANIZZAZIONE_ID'] ?? null;
$reparto_id_utente = $_SESSION['reparto_id'] ?? $_SESSION['utente']['reparto_id'] ?? $_SESSION['utente']['REPARTO_ID'] ?? null;

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

// Se in sessione mancano organizzazione_id o reparto_id, li recuperiamo dal DB per l'utente loggato
if (empty($org_id_utente) || empty($reparto_id_utente)) {
    $resUCorr = supabase_request("staging_utenti", "?id=eq.$utente_id&select=organizzazione_id,reparto_id");
    $dataUCorr = is_array($resUCorr) ? ($resUCorr['data'] ?? $resUCorr) : [];
    if (!empty($dataUCorr) && is_array($dataUCorr)) {
        if (empty($org_id_utente)) {
            $org_id_utente = $dataUCorr[0]['organizzazione_id'] ?? null;
        }
        if (empty($reparto_id_utente)) {
            $reparto_id_utente = $dataUCorr[0]['reparto_id'] ?? null;
        }
    }
}

// Filtro Mese, Anno e Reparto
$mese_selezionato = $_GET['mese'] ?? date('m');
$anno_selezionato = $_GET['anno'] ?? date('Y');

// Gestione Reparto Selezionato basata sui permessi
$reparto_selezionato = '';
if ($is_super_admin || $is_capo_personale) {
    if (isset($_GET['reparto_id']) && $_GET['reparto_id'] !== '') {
        $reparto_selezionato = $_GET['reparto_id'];
    }
} else {
    $reparto_selezionato = $reparto_id_utente;
}

// Numero di giorni nel mese selezionato
$giorni_nel_mese = cal_days_in_month(CAL_GREGORIAN, (int)$mese_selezionato, (int)$anno_selezionato);

// Calcolo range date per il mese
$primo_giorno = sprintf('%04d-%02d-01 00:00:00', $anno_selezionato, $mese_selezionato);
$ultimo_giorno = sprintf('%04d-%02d-%02d 23:59:59', $anno_selezionato, $mese_selezionato, $giorni_nel_mese);

// Recupero reparti disponibili (filtrati per organizzazione se capo personale)
$reparti_query = '?select=id,nome_reparto,organizzazione_id&order=nome_reparto.asc';
if ($is_capo_personale && !empty($org_id_utente)) {
    $reparti_query .= '&organizzazione_id=eq.' . $org_id_utente;
} elseif (!$is_super_admin && !empty($org_id_utente)) {
    $reparti_query .= '&organizzazione_id=eq.' . $org_id_utente;
}
$resp_reparti = supabase_request('reparti', $reparti_query);
$elenco_reparti = is_array($resp_reparti) ? ($resp_reparti['data'] ?? $resp_reparti) : [];

// Determinazione nome struttura e reparto corrente per l'intestazione
$nome_reparto_selezionato = ($is_super_admin || ($is_capo_personale && $reparto_selezionato === '')) ? "Tutti i Reparti della Struttura" : "Reparto non assegnato";
$nome_struttura_corrente = "Azienda Sanitaria / Struttura";

if (!empty($org_id_utente)) {
    $res_org_filtered = supabase_request("organizzazioni", "?id=eq.$org_id_utente&select=nome_struttura");
    $data_org_f = is_array($res_org_filtered) ? ($res_org_filtered['data'] ?? $res_org_filtered) : [];
    if (!empty($data_org_f) && is_array($data_org_f)) {
        $nome_struttura_corrente = $data_org_f[0]['nome_struttura'] ?? $data_org_f[0]['NOME_STRUTTURA'] ?? 'Azienda Sanitaria';
    }
}

if ($reparto_selezionato !== '') {
    foreach ($elenco_reparti as $rep) {
        if ((string)$rep['id'] === (string)$reparto_selezionato) {
            $nome_reparto_selezionato = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
            break;
        }
    }
}

// 1. Recupero delle tipologie di turno dalla tabella tipologie_turno
$tipi_turno_query = '?select=*';
if (!empty($org_id_utente)) {
    $tipi_turno_query .= '&organizzazione_id=eq.' . $org_id_utente;
}
$resp_tipi = supabase_request('tipologie_turno', $tipi_turno_query);
$data_tipi = is_array($resp_tipi) ? ($resp_tipi['data'] ?? $resp_tipi) : [];

$mappa_ore_turni = [];
$mappa_tipo_categoria = []; 
if (is_array($data_tipi)) {
    foreach ($data_tipi as $t) {
        $sigla1 = strtoupper(trim($t['codice_breve'] ?? ''));
        $sigla2 = strtoupper(trim($t['nome_turno'] ?? ''));
        $nome_turno_lower = strtolower($t['nome_turno'] ?? '');
        
        $ore = 0;
        if (!empty($t['orario_inizio']) && !empty($t['orario_fine'])) {
            $inizio = strtotime($t['orario_inizio']);
            $fine = strtotime($t['orario_fine']);
            if ($fine <= $inizio) {
                $fine += 86400; // Turno notturno
            }
            $ore = ($fine - $inizio) / 3600;
        } else {
            $ore = floatval($t['ore'] ?? $t['durata'] ?? $t['numero_ore'] ?? 0);
        }

        // Categorizzazione
        $categoria = 'ordinario';
        if (str_starts_with($sigla1, 'ST') || str_starts_with($sigla2, 'ST') || strpos($nome_turno_lower, 'straordinario') !== false) {
            $categoria = 'straordinario';
        } elseif (str_starts_with($sigla1, 'R') || str_starts_with($sigla2, 'R') || strpos($nome_turno_lower, 'reperibilit') !== false) {
            $categoria = 'reperibilita';
        }

        if (!empty($sigla1)) {
            $mappa_ore_turni[$sigla1] = $ore;
            $mappa_tipo_categoria[$sigla1] = $categoria;
        }
        if (!empty($sigla2)) {
            $mappa_ore_turni[$sigla2] = $ore;
            $mappa_tipo_categoria[$sigla2] = $categoria;
        }
    }
}

// 2. Recupero dipendenti dalla tabella staging_utenti con isolamento multi-tenant rigoroso
$dipendenti_query = '?select=id,nome,email,reparto_id,ruolo,organizzazione_id';
$filtri_dip = [];

if ($is_super_admin) {
    if (!empty($reparto_selezionato)) {
        $filtri_dip[] = 'reparto_id=eq.' . $reparto_selezionato;
    }
} elseif ($is_capo_personale) {
    if (!empty($org_id_utente)) {
        $filtri_dip[] = 'organizzazione_id=eq.' . $org_id_utente;
    }
    if (!empty($reparto_selezionato)) {
        $filtri_dip[] = 'reparto_id=eq.' . $reparto_selezionato;
    }
} else {
    // Coordinatore / Altri ruoli: ristretto al proprio reparto
    if (!empty($org_id_utente)) {
        $filtri_dip[] = 'organizzazione_id=eq.' . $org_id_utente;
    }
    if (!empty($reparto_id_utente)) {
        $filtri_dip[] = 'reparto_id=eq.' . $reparto_id_utente;
    }
}

if (!empty($filtri_dip)) {
    $dipendenti_query .= '&' . implode('&', $filtri_dip);
}

$resp_dip = supabase_request('staging_utenti', $dipendenti_query);
$data_dip = is_array($resp_dip) ? ($resp_dip['data'] ?? $resp_dip) : [];
$dipendenti = [];

if (is_array($data_dip)) {
    foreach ($data_dip as $c) {
        $ruoloC_raw = trim($c['ruolo'] ?? '');
        $ruoloC_lower = strtolower(str_replace([' ', '-'], '_', $ruoloC_raw));
        // Escludiamo amministratori e capi personali dall'elenco operativo dei dipendenti turnisti
        if (in_array($ruoloC_lower, ['super_admin', 'admin', 'superadmin', 'capo_personale', 'capopersonale'])) {
            continue;
        }
        $dipendenti[] = $c;
    }
}

usort($dipendenti, function($a, $b) {
    $nome_a = trim($a['nome'] ?? $a['email'] ?? '');
    $nome_b = trim($b['nome'] ?? $b['email'] ?? '');
    return strcasecmp($nome_a, $nome_b);
});

// Raccogliamo gli ID dei dipendenti filtrati
$array_id_dipendenti = array_column($dipendenti, 'id');

// 3. Recupero turni/eventi dalla tabella pianificazione nel mese selezionato
$elenco_turni = [];
if (!empty($array_id_dipendenti)) {
    $pianificazione_query = '?data_inizio=gte.' . urlencode($primo_giorno) . '&data_inizio=lte.' . urlencode($ultimo_giorno) . '&select=*';
    if (!empty($org_id_utente)) {
        $pianificazione_query .= '&organizzazione_id=eq.' . $org_id_utente;
    }
    $resp_turni = supabase_request('pianificazione', $pianificazione_query);
    $data_turni = is_array($resp_turni) ? ($resp_turni['data'] ?? $resp_turni) : [];
    $elenco_turni = is_array($data_turni) ? $data_turni : [];
}

// Organizziamo i turni in una matrice [utente_id][giorno][] = sigla_turno
$griglia_turni = [];
foreach ($elenco_turni as $trn) {
    $uid = $trn['utente_id'] ?? null;
    $data_inizio = $trn['data_inizio'] ?? '';
    $sigla = strtoupper(trim($trn['tipo_evento'] ?? $trn['turno'] ?? ''));
    
    if ($uid && in_array($uid, $array_id_dipendenti) && !empty($data_inizio) && !empty($sigla)) {
        $giorno_num = (int)date('j', strtotime($data_inizio));
        $griglia_turni[$uid][$giorno_num][] = $sigla;
    }
}

$nomi_mesi = [
    '01' => 'Gennaio', '02' => 'Febbraio', '03' => 'Marzo', '04' => 'Aprile',
    '05' => 'Maggio', '06' => 'Giugno', '07' => 'Luglio', '08' => 'Agosto',
    '09' => 'Settembre', '10' => 'Ottobre', '11' => 'Novembre', '12' => 'Dicembre'
];

$nomi_giorni_settimana = ['D', 'L', 'M', 'M', 'G', 'V', 'S'];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Riepilogo Ore - PRO-TUR</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8fafc; padding-bottom: 70px; color: #334155; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05); background: #ffffff; }
        .nav-bottom { position: fixed; bottom: 0; width: 100%; height: 60px; background: white; border-top: 1px solid #ddd; display: flex; justify-content: space-around; align-items: center; z-index: 1000; }
        
        /* Stile Tabella Excel */
        .table-excel-container { width: 100%; overflow-x: auto; background: white; border-radius: 10px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05); }
        .table-excel { font-size: 0.8rem; border-collapse: collapse; white-space: nowrap; margin-bottom: 0; }
        .table-excel th, .table-excel td { border: 1px solid #dee2e6; text-align: center; vertical-align: middle; padding: 6px 4px; }
        .table-excel th.col-dipendente, .table-excel td.col-dipendente {
            position: sticky; left: 0; background: #ffffff; z-index: 2; text-align: left; min-width: 150px; max-width: 150px; font-weight: bold; padding-left: 10px; box-shadow: 2px 0 5px rgba(0,0,0,0.05);
        }
        .table-excel thead th.col-dipendente { z-index: 3; background: #e9ecef; }
        .table-excel thead th { background: #e9ecef; color: #495057; font-weight: bold; }
        .turno-cell { font-weight: bold; min-width: 32px; height: 32px; font-size: 0.75rem; }
        .turno-m { background-color: #cff4fc !important; color: #055160; }
        .turno-p { background-color: #fff3cd !important; color: #664d03; }
        .turno-n { background-color: #cfe2ff !important; color: #084298; }
        .turno-s { background-color: #f8d7da !important; color: #842029; }
        .turno-r { background-color: #d1e7dd !important; color: #0f5132; }
        .turno-st { background-color: #f3ccff !important; color: #58156e; }
        .turno-rep { background-color: #fff3cd !important; color: #856404; }

        /* Stampa Professionale / Ottimizzazione PDF */
        .print-header { display: none; }
        @media print {
            @page { size: landscape; margin: 10mm; }
            body { background-color: white !important; padding-bottom: 0 !important; font-size: 10pt; color: #000; }
            .nav-bottom, .card.mb-3, .btn, form, nav { display: none !important; }
            .print-header { display: block !important; margin-bottom: 15px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .table-excel-container { box-shadow: none !important; overflow: visible !important; border: none !important; }
            .table-excel { font-size: 0.7rem !important; width: 100% !important; }
            .table-excel th.col-dipendente, .table-excel td.col-dipendente { position: static !important; box-shadow: none !important; background: #fff !important; }
            .table-excel th, .table-excel td { border: 1px solid #999 !important; padding: 4px 2px !important; color: #000 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .alert { display: none !important; }
            .print-footer { display: flex !important; justify-content: space-between; margin-top: 30px; font-size: 0.8rem; }
        }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Riepilogo Ore</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link active" href="riepilogo_ore.php">Riepilogo Ore</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

<div class="container-fluid px-4">
    <!-- Intestazione visibile solo in stampa -->
    <div class="print-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h3 class="fw-bold mb-1">PRO-TUR - Report Presenze e Turni</h3>
                <p class="mb-0 text-muted">Struttura: <strong><?php echo htmlspecialchars($nome_struttura_corrente); ?></strong> | Reparto: <strong><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong></p>
                <p class="mb-0 text-muted">Periodo di Riferimento: <strong><?php echo ($nomi_mesi[$mese_selezionato] ?? $mese_selezionato) . ' ' . $anno_selezionato; ?></strong></p>
            </div>
            <div class="text-end">
                <small class="text-muted">Data stampa: <?php echo date('d/m/Y H:i'); ?></small>
            </div>
        </div>
    </div>

    <!-- Header con Box Struttura / Reparto e Filtro Reparto coerente con turni.php e ferie.php -->
    <div class="card shadow-sm mb-4 p-3">
        <div class="row align-items-center g-3">
            <div class="col-md-7">
                <h2 class="mb-1 fw-bold text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-bar-chart-fill text-primary"></i> Riepilogo Ore & Turni Mensili
                </h2>
                <span class="small text-muted">
                    <i class="bi bi-building"></i> Struttura: <strong class="text-dark"><?php echo htmlspecialchars($nome_struttura_corrente); ?></strong> | 
                    Reparto: <strong class="text-dark"><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong>
                </span>
            </div>
            <div class="col-md-5 text-md-end">
                <button onclick="window.print()" class="btn btn-primary btn-sm fw-bold">
                    <i class="bi bi-printer-fill me-1"></i> Stampa / Esporta PDF
                </button>
            </div>
        </div>
    </div>

    <!-- Filtri Mese, Anno e Reparto -->
    <div class="card p-3 mb-4 shadow-sm">
        <form method="GET" action="riepilogo_ore.php" class="row g-2 align-items-end">
            <div class="<?php echo ($is_super_admin || $is_capo_personale) ? 'col-md-4' : 'col-md-6'; ?>">
                <label for="mese" class="form-label small fw-bold">Mese</label>
                <select name="mese" id="mese" class="form-select form-select-sm">
                    <?php foreach ($nomi_mesi as $num => $nome): ?>
                        <option value="<?php echo $num; ?>" <?php echo ($mese_selezionato === $num) ? 'selected' : ''; ?>>
                            <?php echo $nome; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="<?php echo ($is_super_admin || $is_capo_personale) ? 'col-md-4' : 'col-md-6'; ?>">
                <label for="anno" class="form-label small fw-bold">Anno</label>
                <select name="anno" id="anno" class="form-select form-select-sm">
                    <?php for ($a = date('Y') - 1; $a <= date('Y') + 1; $a++): ?>
                        <option value="<?php echo $a; ?>" <?php echo ((int)$anno_selezionato === $a) ? 'selected' : ''; ?>>
                            <?php echo $a; ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <?php if ($is_super_admin || $is_capo_personale): ?>
            <div class="col-md-4">
                <label for="reparto_id" class="form-label small fw-bold">Reparto</label>
                <select name="reparto_id" id="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Tutti i Reparti della Struttura</option>
                    <?php foreach ($elenco_reparti as $rep): 
                        $labelRep = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
                    ?>
                        <option value="<?php echo $rep['id']; ?>" <?php echo ($reparto_selezionato !== '' && (string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($labelRep); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-12 text-end mt-2">
                <button type="submit" class="btn btn-dark btn-sm fw-bold">
                    <i class="bi bi-filter me-1"></i> Applica Filtri
                </button>
            </div>
        </form>
    </div>

    <!-- Griglia Tabellare Stile Excel -->
    <?php if (empty($dipendenti)): ?>
        <div class="alert alert-warning text-center shadow-sm">Nessun dipendente trovato per questo reparto o struttura nel sistema.</div>
    <?php else: ?>
        <div class="table-excel-container mb-4 shadow-sm">
            <table class="table table-excel">
                <thead>
                    <tr>
                        <th class="col-dipendente">Dipendente</th>
                        <?php for ($g = 1; $g <= $giorni_nel_mese; $g++): 
                            $timestamp_giorno = strtotime(sprintf('%04d-%02d-%02d', $anno_selezionato, $mese_selezionato, $g));
                            $num_settimana = date('w', $timestamp_giorno);
                            $lettera_giorno = $nomi_giorni_settimana[$num_settimana];
                            $is_festivo = ($num_settimana == 0 || $num_settimana == 6);
                        ?>
                            <th class="<?php echo $is_festivo ? 'bg-light text-danger' : ''; ?>" style="min-width: 35px;">
                                <div style="font-size: 0.60rem; opacity: 0.7;"><?php echo $lettera_giorno; ?></div>
                                <div><?php echo $g; ?></div>
                            </th>
                        <?php endfor; ?>
                        <th class="bg-primary text-white text-center" style="min-width: 45px;" title="Turni totali">Turni</th>
                        <th class="bg-success text-white text-center" style="min-width: 55px;" title="Ore Ordinarie">Ord.</th>
                        <th class="bg-warning text-dark text-center" style="min-width: 55px;" title="Ore Straordinarie">Straor.</th>
                        <th class="bg-info text-dark text-center" style="min-width: 55px;" title="Ore Reperibilità">Reper.</th>
                        <th class="bg-dark text-white text-center" style="min-width: 60px;" title="Totale Complessivo Ore">Tot.</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dipendenti as $dip): 
                        $dip_id = $dip['id'] ?? '';
                        $nome_completo = trim($dip['nome'] ?? '');
                        if (empty($nome_completo)) { $nome_completo = $dip['email'] ?? 'Utente #' . substr($dip_id, 0, 5); }

                        $ore_ordinarie = 0;
                        $ore_straordinarie = 0;
                        $ore_reperibilita = 0;
                        $conteggio_turni = 0;
                    ?>
                        <tr>
                            <td class="col-dipendente text-truncate" title="<?php echo htmlspecialchars($nome_completo); ?>">
                                <i class="bi bi-person-circle text-primary me-1"></i> <?php echo htmlspecialchars($nome_completo); ?>
                            </td>

                            <?php for ($g = 1; $g <= $giorni_nel_mese; $g++): 
                                $lista_sigle = $griglia_turni[$dip_id][$g] ?? [];
                                $testo_cella = '';
                                $classe_colore = '';

                                if (!empty($lista_sigle)) {
                                    $conteggio_turni += count($lista_sigle);
                                    $testo_cella = implode('<br>', $lista_sigle);
                                    
                                    foreach ($lista_sigle as $sigla_u) {
                                        $categoria = $mappa_tipo_categoria[$sigla_u] ?? 'ordinario';
                                        $ore_turno = $mappa_ore_turni[$sigla_u] ?? 0;

                                        if ($categoria == 'straordinario') {
                                            $ore_straordinarie += $ore_turno;
                                            if (empty($classe_colore)) $classe_colore = 'turno-st';
                                        } elseif ($categoria == 'reperibilita') {
                                            $ore_reperibilita += $ore_turno;
                                            if (empty($classe_colore)) $classe_colore = 'turno-rep';
                                        } else {
                                            $ore_ordinarie += $ore_turno;
                                            if (empty($classe_colore)) {
                                                if ($sigla_u == 'M') $classe_colore = 'turno-m';
                                                elseif ($sigla_u == 'P') $classe_colore = 'turno-p';
                                                elseif ($sigla_u == 'N') $classe_colore = 'turno-n';
                                                elseif ($sigla_u == 'S') $classe_colore = 'turno-s';
                                                elseif ($sigla_u == 'R') $classe_colore = 'turno-r';
                                            }
                                        }
                                    }
                                }
                            ?>
                                <td class="turno-cell <?php echo $classe_colore; ?>">
                                    <?php echo $testo_cella; ?>
                                </td>
                            <?php endfor; ?>

                            <!-- Totale Turni -->
                            <td class="fw-bold text-center bg-light">
                                <?php echo $conteggio_turni > 0 ? $conteggio_turni : '-'; ?>
                            </td>

                            <!-- Ore Ordinarie -->
                            <td class="fw-bold text-center text-success bg-light">
                                <?php echo $ore_ordinarie > 0 ? number_format($ore_ordinarie, 1, ',', '.') . 'h' : '-'; ?>
                            </td>

                            <!-- Ore Straordinarie -->
                            <td class="fw-bold text-center text-danger bg-light">
                                <?php echo $ore_straordinarie > 0 ? number_format($ore_straordinarie, 1, ',', '.') . 'h' : '-'; ?>
                            </td>

                            <!-- Ore Reperibilità -->
                            <td class="fw-bold text-center text-dark bg-light">
                                <?php echo $ore_reperibilita > 0 ? number_format($ore_reperibilita, 1, ',', '.') . 'h' : '-'; ?>
                            </td>

                            <!-- Totale Complessivo -->
                            <td class="fw-bold text-center text-primary bg-light">
                                <?php 
                                    $tot_complessivo = $ore_ordinarie + $ore_straordinarie;
                                    echo $tot_complessivo > 0 ? number_format($tot_complessivo, 1, ',', '.') . 'h' : '-'; 
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Sezione firme per la stampa cartacea -->
        <div class="print-footer d-none">
            <div>
                <p class="mb-1">Il Coordinatore Sanitario</p>
                <br><br>
                <p class="mb-0">___________________________</p>
            </div>
            <div>
                <p class="mb-1">Timbro e Firma della Direzione</p>
                <br><br>
                <p class="mb-0">___________________________</p>
            </div>
        </div>

        <div class="alert alert-info small mt-2 mb-4 shadow-sm">
            <i class="bi bi-info-circle-fill me-1"></i> Powered by - PRO-TUR GD - Gestione integrata turni.
        </div>
    <?php endif; ?>
</div>

<div class="nav-bottom no-print">
    <a href="dashboard.php" class="text-secondary text-decoration-none"><i class="bi bi-house-door fs-4"></i></a>
    <a href="planner.php" class="text-secondary text-decoration-none"><i class="bi bi-calendar3 fs-4"></i></a>
    <a href="turni.php" class="text-secondary text-decoration-none"><i class="bi bi-clock-history fs-4"></i></a>
    <a href="riepilogo_ore.php" class="text-primary text-decoration-none"><i class="bi bi-bar-chart-fill fs-4"></i></a>
    <a href="logout.php" class="text-danger text-decoration-none"><i class="bi bi-box-arrow-right fs-4"></i></a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
