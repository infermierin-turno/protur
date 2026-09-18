<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

$DEBUG_MODE = false;
$debug_log = [];

function supabase_staging_request($endpoint) {
    global $DEBUG_MODE, $debug_log;
    $url = SUPABASE_URL . '/rest/v1/' . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'apikey: ' . SUPABASE_KEY,
        'Authorization: Bearer ' . SUPABASE_KEY,
        'Content-Type: application/json',
        'Accept-Profile: public',
        'Content-Profile: public'
    ]);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($response, true);
    }
    return [];
}

$utente_id = $_SESSION['utente_id'] ?? $_SESSION['utente']['id'] ?? $_SESSION['utente']['ID'] ?? null;
$session_email = $_SESSION['email'] ?? $_SESSION['utente']['email'] ?? $_SESSION['utente']['EMAIL'] ?? null;
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

$mappaUtenti = [];
$resCollabAll = supabase_staging_request("staging_utenti?select=id,nome,email,ruolo,reparto_id,squadra&order=nome.asc");
if (is_array($resCollabAll)) {
    foreach ($resCollabAll as $c) {
        if (!empty($c['id'])) {
            $mappaUtenti[$c['id']] = $c;
        }
    }
}

if (!empty($utente_id) && !isset($mappaUtenti[$utente_id])) {
    if (!empty($session_email)) {
        foreach ($resCollabAll as $c) {
            if (isset($c['email']) && strcasecmp(trim($c['email']), trim($session_email)) === 0) {
                $utente_id = $c['id'];
                $_SESSION['utente_id'] = $utente_id;
                break;
            }
        }
    }
}

if (empty($utente_id)) {
    header("Location: index.php");
    exit;
}

if ($is_coordinatore && empty($reparto_id_utente) && isset($mappaUtenti[$utente_id])) {
    $reparto_id_utente = $mappaUtenti[$utente_id]['reparto_id'] ?? null;
    if (empty($org_id_utente)) {
        $org_id_utente = $mappaUtenti[$utente_id]['organizzazione_id'] ?? null;
    }
}

$messaggio = '';
$tipo_alert = '';

// Gestione Sblocco Desiderata personalizzato per ciascun coordinatore/reparto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'toggle_sblocco') {
    if ($is_coordinatore || $is_super_admin || $is_capo_personale) {
        $target_reparto_id = trim($_POST['reparto_id'] ?? $reparto_id_utente);
        $nuovo_stato_sblocco = isset($_POST['stato_sblocco']) ? (bool)$_POST['stato_sblocco'] : false;

        if ($is_super_admin || $is_capo_personale) {
            if (!isset($_SESSION['sblocco_desiderata_reparto'])) {
                $_SESSION['sblocco_desiderata_reparto'] = [];
            }
            if (!empty($target_reparto_id)) {
                $_SESSION['sblocco_desiderata_reparto'][$target_reparto_id] = $nuovo_stato_sblocco;
            }
        } else {
            if (!empty($reparto_id_utente)) {
                if (!isset($_SESSION['sblocco_desiderata_reparto'])) {
                    $_SESSION['sblocco_desiderata_reparto'] = [];
                }
                $_SESSION['sblocco_desiderata_reparto'][$reparto_id_utente] = $nuovo_stato_sblocco;
            }
        }
        $_SESSION['sblocco_desiderata'] = $nuovo_stato_sblocco;

        header("Location: ferie.php?msg=sblocco_aggiornato");
        exit;
    }
}

function isRepartoSbloccato($reparto_id_target) {
    if (isset($_SESSION['sblocco_desiderata_reparto']) && is_array($_SESSION['sblocco_desiderata_reparto'])) {
        if (!empty($reparto_id_target) && isset($_SESSION['sblocco_desiderata_reparto'][$reparto_id_target])) {
            return (bool)$_SESSION['sblocco_desiderata_reparto'][$reparto_id_target];
        }
    }
    return $_SESSION['sblocco_desiderata'] ?? false;
}

$reparto_utente_corrente = $mappaUtenti[$utente_id]['reparto_id'] ?? $reparto_id_utente;
$sblocco_attivo = isRepartoSbloccato($reparto_utente_corrente);
$giorno_corrente = (int)date('j');

$inserimento_bloccato = false;
if ($giorno_corrente > 16 && $giorno_corrente <= 30 && !$sblocco_attivo && !$is_coordinatore && !$is_super_admin && !$is_capo_personale) {
    $inserimento_bloccato = true;
}

$listaCollaboratoriDropdown = [];
if (is_array($resCollabAll)) {
    foreach ($resCollabAll as $c) {
        $idC = $c['id'] ?? '';
        $ruoloC_raw = trim($c['ruolo'] ?? '');
        $ruoloC_lower = strtolower(str_replace([' ', '-'], '_', $ruoloC_raw));
        
        $is_admin_collab = ($ruoloC_lower === 'super_admin' || $ruoloC_lower === 'admin' || $ruoloC_lower === 'superadmin' || $ruoloC_lower === 'capo_personale' || $ruoloC_lower === 'capopersonale');
        if ($is_admin_collab) {
            continue;
        }

        if ($is_coordinatore && !$is_super_admin && !$is_capo_personale) {
            if (!empty($reparto_id_utente) && isset($c['reparto_id']) && (string)$c['reparto_id'] !== (string)$reparto_id_utente) {
                continue;
            }
        }

        $listaCollaboratoriDropdown[] = $c;
    }
}

$statisticheWeekendFerie = [];
$resAssenzeAll = supabase_staging_request("assenze?select=utente_id,data_inizio,data_fine,stato,tipo_assenza");
if (is_array($resAssenzeAll)) {
    foreach ($resAssenzeAll as $ass) {
        $uId = $ass['utente_id'] ?? '';
        $statoAss = $ass['stato'] ?? '';
        if (empty($uId) || $statoAss === 'rifiutato') continue;

        $dInizio = $ass['data_inizio'] ?? '';
        $dFine = $ass['data_fine'] ?? '';
        if (empty($dInizio) || empty($dFine)) continue;

        $startObj = new DateTime($dInizio);
        $endObj = new DateTime($dFine);
        $endObj->modify('+1 day');
        $interval = new DateInterval('P1D');
        $period = new DatePeriod($startObj, $interval, $endObj);

        $weekendToccati = [];
        foreach ($period as $dt) {
            $n = (int)$dt->format('N');
            if ($n === 6) {
                $weekendToccati[$dt->format('Y-m-d')] = true;
            } elseif ($n === 7) {
                $sabatoPrec = clone $dt;
                $sabatoPrec->modify('-1 day');
                $weekendToccati[$sabatoPrec->format('Y-m-d')] = true;
            }
        }

        if (!isset($statisticheWeekendFerie[$uId])) {
            $statisticheWeekendFerie[$uId] = 0;
        }
        $statisticheWeekendFerie[$uId] += count($weekendToccati);
    }
}

function includeWeekend($dataInizio, $dataFine) {
    $start = new DateTime($dataInizio);
    $end = new DateTime($dataFine);
    $end->modify('+1 day');
    $interval = new DateInterval('P1D');
    $period = new DatePeriod($start, $interval, $end);

    foreach ($period as $dt) {
        $n = (int)$dt->format('N');
        if ($n === 6 || $n === 7) {
            return true;
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $azione = $_POST['azione'] ?? '';

    if ($azione === 'nuova_assenza') {
        $collaboratore_selezionato = ($is_coordinatore || $is_super_admin || $is_capo_personale) ? trim($_POST['collaboratore_id'] ?? $utente_id) : $utente_id;
        if (empty($collaboratore_selezionato) || !isset($mappaUtenti[$collaboratore_selezionato])) {
            $collaboratore_selezionato = $utente_id;
        }

        $reparto_collaboratore_target = $mappaUtenti[$collaboratore_selezionato]['reparto_id'] ?? $reparto_id_utente;
        $sblocco_target_attivo = isRepartoSbloccato($reparto_collaboratore_target);
        
        $blocco_corrente = false;
        if ($giorno_corrente > 16 && $giorno_corrente <= 30 && !$sblocco_target_attivo && !$is_coordinatore && !$is_super_admin && !$is_capo_personale) {
            $blocco_corrente = true;
        }

        if ($blocco_corrente) {
            $messaggio = "Termine ultimo superato (16 del mese). L'inserimento delle desiderata è bloccato in attesa dello sblocco da parte del coordinatore.";
            $tipo_alert = "danger";
        } else {
            $tipo_assenza = trim($_POST['tipo'] ?? 'ferie');
            $data_inizio = trim($_POST['data_inizio'] ?? '');
            $data_fine = trim($_POST['data_fine'] ?? '');
            $note_utente = trim($_POST['note'] ?? '');
            
            $stato_iniziale = 'in attesa';

            if (!empty($data_inizio) && !empty($data_fine)) {
                $squadra_collaboratore = $mappaUtenti[$collaboratore_selezionato]['squadra'] ?? 'Senza Squadra';
                $richiede_weekend = includeWeekend($data_inizio, $data_fine);
                $mieiWeekendFatti = $statisticheWeekendFerie[$collaboratore_selezionato] ?? 0;

                $conflitto_rilevato = false;
                $dettagli_conflitto = '';

                $resAssEsistenti = supabase_staging_request("assenze?select=*");
                if (is_array($resAssEsistenti)) {
                    foreach ($resAssEsistenti as $assEsistente) {
                        $altroUtenteId = $assEsistente['utente_id'] ?? '';
                        if ($altroUtenteId === $collaboratore_selezionato) continue;

                        if (isset($mappaUtenti[$altroUtenteId])) {
                            $squadraAltro = $mappaUtenti[$altroUtenteId]['squadra'] ?? 'Senza Squadra';

                            if (!empty($squadra_collaboratore) && strcasecmp($squadraAltro, $squadra_collaboratore) === 0) {
                                $InizioEsistente = $assEsistente['data_inizio'] ?? '';
                                $FineEsistente = $assEsistente['data_fine'] ?? '';
                                $statoEsistente = $assEsistente['stato'] ?? '';

                                if ($statoEsistente !== 'rifiutato') {
                                    if (($data_inizio <= $FineEsistente) && ($data_fine >= $InizioEsistente)) {
                                        $conflitto_rilevato = true;
                                        $nomeAltro = $mappaUtenti[$altroUtenteId]['nome'] ?? 'Un collega';
                                        $weekendAltro = $statisticheWeekendFerie[$altroUtenteId] ?? 0;

                                        if ($richiede_weekend) {
                                            if ($mieiWeekendFatti > $weekendAltro) {
                                                $dettagli_conflitto = " [CONFLITTO SQUADRA & EQUITÀ WEEKEND]: Sovrapposizione con $nomeAltro. Tu hai già goduto di $mieiWeekendFatti weekend, mentre $nomeAltro ne ha registrati $weekendAltro.";
                                            } else {
                                                $dettagli_conflitto = " [CONFLITTO SQUADRA & EQUITÀ WEEKEND]: Sovrapposizione nelle stesse date con $nomeAltro (Weekend goduti: Tuo $mieiWeekendFatti vs Collega $weekendAltro).";
                                            }
                                        } else {
                                            $dettagli_conflitto = " [CONFLITTO SQUADRA]: Sovrapposizione nelle stesse date con $nomeAltro.";
                                        }
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                $nota_finale = $note_utente;
                if ($conflitto_rilevato) {
                    $nota_finale = trim("IN ATTESA (VERIFICA EQUITÀ/SQUADRA): " . $note_utente . $dettagli_conflitto);
                    $messaggio = "La richiesta è stata inserita ed è IN ATTESA: il sistema ha rilevato una sovrapposizione di squadra valutando anche i criteri di equità dei weekend.";
                    $tipo_alert = "warning";
                } else {
                    $messaggio = "Richiesta registrata con successo! Controllo equità weekend per squadra superato senza conflitti.";
                    $tipo_alert = "success";
                }

                $datiAssenza = [
                    'utente_id' => $collaboratore_selezionato,
                    'reparto_id' => !empty($reparto_collaboratore_target) ? $reparto_collaboratore_target : null,
                    'tipo_assenza' => $tipo_assenza,
                    'data_inizio' => $data_inizio,
                    'data_fine' => $data_fine,
                    'stato' => $stato_iniziale,
                    'note' => !empty($nota_finale) ? $nota_finale : null
                ];

                $ch = curl_init(SUPABASE_URL . '/rest/v1/assenze');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($datiAssenza));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'apikey: ' . SUPABASE_KEY,
                    'Authorization: Bearer ' . SUPABASE_KEY,
                    'Content-Type: application/json',
                    'Accept-Profile: public',
                    'Content-Profile: public',
                    'Prefer: return=representation'
                ]);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $resp = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if (!($code >= 200 && $code < 300)) {
                    $messaggio = "Errore durante il salvataggio dell'assenza (HTTP $code). Dettaglio: " . htmlspecialchars($resp);
                    $tipo_alert = "danger";
                }
            } else {
                $messaggio = "Inserisci obbligatoriamente la data di inizio e di fine.";
                $tipo_alert = "danger";
            }
        }
    }
}

if (isset($_GET['azione_stato']) && isset($_GET['id'])) {
    $id_assenza = trim($_GET['id']);
    $nuovo_stato = trim($_GET['azione_stato']);

    if ($nuovo_stato === 'eliminato') {
        $resAssSingle = supabase_staging_request("assenze?id=eq." . urlencode($id_assenza) . "&select=*");
        if (!empty($resAssSingle) && is_array($resAssSingle)) {
            $assData = $resAssSingle[0];
            
            if (!$is_coordinatore && !$is_super_admin && !$is_capo_personale) {
                if (($assData['utente_id'] ?? '') !== $utente_id || ($assData['stato'] ?? '') !== 'in attesa') {
                    header("Location: ferie.php?msg=permesso_negato");
                    exit;
                }
            }

            $chDelPian = curl_init(SUPABASE_URL . '/rest/v1/pianificazione?utente_id=eq.' . urlencode($assData['utente_id']) . '&data_inizio=gte.' . urlencode($assData['data_inizio']) . '&data_inizio=lte.' . urlencode($assData['data_fine']));
            curl_setopt($chDelPian, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($chDelPian, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($chDelPian, CURLOPT_HTTPHEADER, [
                'apikey: ' . SUPABASE_KEY,
                'Authorization: Bearer ' . SUPABASE_KEY,
                'Content-Type: application/json',
                'Accept-Profile: public',
                'Content-Profile: public'
            ]);
            curl_setopt($chDelPian, CURLOPT_SSL_VERIFYPEER, false);
            curl_exec($chDelPian);
            curl_close($chDelPian);
        }

        $ch = curl_init(SUPABASE_URL . '/rest/v1/assenze?id=eq.' . urlencode($id_assenza));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Accept-Profile: public',
            'Content-Profile: public'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);

    } else if (in_array($nuovo_stato, ['approvato', 'rifiutato'])) {
        if (!$is_coordinatore && !$is_super_admin && !$is_capo_personale) {
            header("Location: ferie.php?msg=permesso_negato");
            exit;
        }

        $ch = curl_init(SUPABASE_URL . '/rest/v1/assenze?id=eq.' . urlencode($id_assenza));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['stato' => $nuovo_stato]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Accept-Profile: public',
            'Content-Profile: public',
            'Prefer: return=representation'
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_exec($ch);
        curl_close($ch);

        if ($nuovo_stato === 'approvato') {
            $resAssSingle = supabase_staging_request("assenze?id=eq." . urlencode($id_assenza) . "&select=*");
            if (!empty($resAssSingle) && is_array($resAssSingle)) {
                $aData = $resAssSingle[0];
                $tipoAssenzaRaw = trim($aData['tipo_assenza'] ?? $aData['tipo'] ?? 'ferie');
                
                $tipoEventoCodice = 'Ferie';
                $tLower = strtolower($tipoAssenzaRaw);
                if (strpos($tLower, 'malattia') !== false) {
                    $tipoEventoCodice = 'Mal';
                } elseif (strpos($tLower, 'permesso') !== false) {
                    $tipoEventoCodice = 'Perm';
                } elseif (strpos($tLower, '104') !== false) {
                    $tipoEventoCodice = '104';
                } elseif (strpos($tLower, 'riposo') !== false) {
                    $tipoEventoCodice = 'Riposo';
                } else {
                    $tipoEventoCodice = ucfirst($tipoAssenzaRaw);
                }

                $dataInizioLoop = $aData['data_inizio'];
                $dataFineLoop = $aData['data_fine'];

                $currentDateObj = new DateTime($dataInizioLoop);
                $endDateObj = new DateTime($dataFineLoop);
                
                while ($currentDateObj <= $endDateObj) {
                    $dataCorrenteStr = $currentDateObj->format('Y-m-d');

                    $datiPianificazione = [
                        'organizzazione_id' => !empty($org_id_utente) ? $org_id_utente : null,
                        'reparto_id' => !empty($aData['reparto_id']) ? $aData['reparto_id'] : (!empty($reparto_id_utente) ? $reparto_id_utente : null),
                        'utente_id' => $aData['utente_id'],
                        'data_inizio' => $dataCorrenteStr,
                        'data_fine' => $dataCorrenteStr,
                        'tipo_evento' => $tipoEventoCodice,
                        'stato' => 'approvato',
                        'note' => $tipoEventoCodice . ' approvata - Blocco turnazione'
                    ];

                    $chPian = curl_init(SUPABASE_URL . '/rest/v1/pianificazione');
                    curl_setopt($chPian, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($chPian, CURLOPT_POST, true);
                    curl_setopt($chPian, CURLOPT_POSTFIELDS, json_encode($datiPianificazione));
                    curl_setopt($chPian, CURLOPT_HTTPHEADER, [
                        'apikey: ' . SUPABASE_KEY,
                        'Authorization: Bearer ' . SUPABASE_KEY,
                        'Content-Type: application/json',
                        'Accept-Profile: public',
                        'Content-Profile: public',
                        'Prefer: return=representation'
                    ]);
                    curl_setopt($chPian, CURLOPT_SSL_VERIFYPEER, false);
                    curl_exec($chPian);
                    curl_close($chPian);

                    $currentDateObj->modify('+1 day');
                }
            }
        }
    }

    header("Location: ferie.php?msg=aggiornato");
    exit;
}

$listaAssenze = [];
$idUtentiReparto = [];

foreach ($mappaUtenti as $uid => $uData) {
    $rU = $uData['reparto_id'] ?? '';
    $ruoloU_raw = trim($uData['ruolo'] ?? '');
    $ruoloU_lower = strtolower(str_replace([' ', '-'], '_', $ruoloU_raw));
    
    if ($ruoloU_lower === 'super_admin' || $ruoloU_lower === 'admin' || $ruoloU_lower === 'superadmin' || $ruoloU_lower === 'capo_personale' || $ruoloU_lower === 'capopersonale') {
        continue;
    }

    if ($is_super_admin || $is_capo_personale || empty($reparto_id_utente) || (string)$rU === (string)$reparto_id_utente) {
        $idUtentiReparto[] = $uid;
    }
}

if ($is_super_admin || $is_capo_personale) {
    $resAssenze = supabase_staging_request("assenze?select=*&order=data_inizio.desc");
    if (is_array($resAssenze)) {
        $listaAssenze = $resAssenze;
    }
} else if ($is_coordinatore) {
    if (!empty($idUtentiReparto)) {
        $inQueryList = '(' . implode(',', $idUtentiReparto) . ')';
        $resAssenze = supabase_staging_request("assenze?utente_id=in." . urlencode($inQueryList) . "&select=*&order=data_inizio.desc");
        if (is_array($resAssenze)) {
            $listaAssenze = $resAssenze;
        }
    } else {
        $listaAssenze = [];
    }
} else {
    $resAssenze = supabase_staging_request("assenze?utente_id=eq.$utente_id&select=*&order=data_inizio.desc");
    if (is_array($resAssenze)) {
        $listaAssenze = $resAssenze;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ferie e Assenze - Gestione Turni Ospedalieri</title>
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
                    <li class="nav-item"><a class="nav-link active" href="ferie.php">Ferie e Assenze</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <h2 class="mb-1 fw-bold"><?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? 'Gestione Ferie, Assenze ed Equità Weekend' : 'Le tue Ferie e Permessi'; ?></h2>
        <h4 class="text-muted mb-4 fs-6"><?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? 'Pianificazione permessi, conflitti di squadra e rotazione weekend del reparto' : 'Inoltra richieste, verifica il bilancio weekend e monitora lo stato delle tue assenze'; ?></h4>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'aggiornato'): ?>
            <div class="alert alert-success py-2 small" role="alert">Stato aggiornato con successo.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'sblocco_aggiornato'): ?>
            <div class="alert alert-info py-2 small" role="alert">Stato sblocco desiderata aggiornato con successo per il reparto.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'permesso_negato'): ?>
            <div class="alert alert-danger py-2 small" role="alert">Operazione non autorizzata.</div>
        <?php endif; ?>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> py-2 small fw-bold" role="alert">
                <i class="bi bi-info-circle-fill"></i> <?php echo htmlspecialchars($messaggio); ?>
            </div>
        <?php } ?>

        <!-- Pannello Coordinatore: Gestione Sblocco Desiderata personalizzato per reparto (16 - 30) -->
        <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale): ?>
        <div class="card shadow-sm mb-4 bg-white border-start border-4 border-warning">
            <div class="card-body d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="mb-1 fs-6 fw-bold"><i class="bi bi-calendar-check text-warning"></i> Controllo Scadenza Desiderata (Scadenza: 16 del mese)</h5>
                    <p class="text-muted small mb-0">Ogni coordinatore gestisce autonomamente lo sblocco per il proprio reparto. Dal 17 al 30 è attivo il blocco standard, modificabile liberamente qui sotto.</p>
                </div>
                <form method="POST" action="ferie.php" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="azione" value="toggle_sblocco">
                    <?php if ($is_super_admin || $is_capo_personale): ?>
                        <input type="hidden" name="reparto_id" value="<?php echo htmlspecialchars($reparto_id_utente); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="stato_sblocco" value="<?php echo $sblocco_attivo ? '0' : '1'; ?>">
                    <span class="small fw-bold text-<?php echo $sblocco_attivo ? 'success' : 'secondary'; ?>">
                        <?php echo $sblocco_attivo ? 'Sblocco Reparto Attivo (fino al 30)' : 'Blocco Standard Reparto Attivo'; ?>
                    </span>
                    <button type="submit" class="btn btn-sm btn-<?php echo $sblocco_attivo ? 'outline-danger' : 'outline-success'; ?> fw-bold">
                        <?php echo $sblocco_attivo ? 'Disattiva Sblocco' : 'Sblocca Fino al 30'; ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Form Inserimento Richiesta Assenza -->
        <div class="card shadow-sm mb-4 bg-white">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0 fs-6 fw-bold"><?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? 'Inserisci Nuova Richiesta / Assenza Collaboratore' : 'Inoltra Nuova Richiesta di Ferie o Permesso'; ?></h5>
            </div>
            <div class="card-body">
                <?php if ($inserimento_bloccato): ?>
                    <div class="alert alert-danger mb-0" role="alert">
                        <i class="bi bi-lock-fill"></i> <strong>Termine scaduto:</strong> Il termine per l'inserimento delle desiderata era fissato al 16 del mese. Attualmente le richieste sono bloccate in attesa di sblocco da parte del coordinatore del reparto.
                    </div>
                <?php else: ?>
                    <div class="alert alert-light border small mb-3 text-muted">
                        <i class="bi bi-shield-check text-primary"></i> <strong>Nota sui controlli automatici:</strong> Al momento dell'invio, il sistema verifica in automatico la sovrapposizione con i colleghi della stessa <strong>squadra</strong> e calcola il bilancio dei <strong>weekend</strong> goduti per garantire l'equità della rotazione.
                    </div>
                    <form method="POST" action="ferie.php">
                        <input type="hidden" name="azione" value="nuova_assenza">
                        <div class="row g-3">
                            <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale): ?>
                                <div class="col-md-3">
                                    <label for="collaboratore_id" class="form-label small fw-bold">Collaboratore (Tuo Reparto)</label>
                                    <select class="form-select" id="collaboratore_id" name="collaboratore_id" required>
                                        <?php foreach ($listaCollaboratoriDropdown as $c) { 
                                            $squadraTxt = !empty($c['squadra']) ? ' [Squadra: ' . $c['squadra'] . ']' : '';
                                        ?>
                                            <option value="<?php echo $c['id']; ?>">
                                                <?php echo htmlspecialchars($c['nome']); ?> (<?php echo htmlspecialchars($c['ruolo'] ?? 'N/D'); ?><?php echo $squadraTxt; ?>)
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                            <?php else: ?>
                                <div class="col-md-4">
                            <?php endif; ?>
                                <label for="tipo" class="form-label small fw-bold">Tipo Assenza</label>
                                <select class="form-select" id="tipo" name="tipo" required>
                                    <option value="ferie">Ferie</option>
                                    <option value="permesso">Permesso</option>
                                    <option value="malattia">Malattia</option>
                                    <option value="104">Legge 104</option>
                                    <option value="riposo">Riposo Compensativo</option>
                                </select>
                            </div>
                            <div class="col-md-<?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? '2' : '3'; ?>">
                                <label for="data_inizio" class="form-label small fw-bold">Data Inizio</label>
                                <input type="date" class="form-control" id="data_inizio" name="data_inizio" required>
                            </div>
                            <div class="col-md-<?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? '2' : '3'; ?>">
                                <label for="data_fine" class="form-label small fw-bold">Data Fine</label>
                                <input type="date" class="form-control" id="data_fine" name="data_fine" required>
                            </div>
                            <div class="col-md-<?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? '2' : '2'; ?>">
                                <label class="form-label small fw-bold d-block">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100 fw-bold"><i class="bi bi-send-fill"></i> Invia</button>
                            </div>
                            <div class="col-12">
                                <label for="note" class="form-label small fw-bold">Note / Motivazione (Opzionale)</label>
                                <textarea class="form-control" id="note" name="note" rows="2" placeholder="Eventuali dettagli o note per il coordinatore..."></textarea>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabella Elenco Assenze / Richieste (STORICO) -->
        <div class="card shadow-sm mb-4 bg-white">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-table"></i> Storico e Stato Richieste</h5>
                <span class="badge bg-secondary"><?php echo count($listaAssenze); ?> richieste</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Collaboratore</th>
                                <th>Reparto / Squadra</th>
                                <th>Tipo</th>
                                <th>Periodo</th>
                                <th>Stato</th>
                                <th>Note / Dettagli Conflitto</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($listaAssenze)): ?>
                                <?php foreach ($listaAssenze as $ass): 
                                    $uIdAss = $ass['utente_id'] ?? '';
                                    $datiUtenteAss = $mappaUtenti[$uIdAss] ?? [];
                                    $nomeCollabAss = $datiUtenteAss['nome'] ?? 'Utente Sconosciuto';
                                    $squadraAss = $datiUtenteAss['squadra'] ?? 'N/D';
                                    $statoAss = $ass['stato'] ?? 'in attesa';
                                    
                                    $badgeStatoBg = 'warning text-dark';
                                    if ($statoAss === 'approvato') $badgeStatoBg = 'success';
                                    if ($statoAss === 'rifiutato') $badgeStatoBg = 'danger';
                                    if ($statoAss === 'in attesa') $badgeStatoBg = 'warning text-dark';
                                ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($nomeCollabAss); ?></strong></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($squadraAss); ?></span></td>
                                    <td><span class="text-uppercase fw-bold small"><?php echo htmlspecialchars($ass['tipo_assenza'] ?? $ass['tipo'] ?? 'ferie'); ?></span></td>
                                    <td>
                                        <?php echo htmlspecialchars($ass['data_inizio']); ?> 
                                        <i class="bi bi-arrow-right text-muted"></i> 
                                        <?php echo htmlspecialchars($ass['data_fine']); ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $badgeStatoBg; ?>">
                                            <?php echo ucfirst($statoAss); ?>
                                        </span>
                                    </td>
                                    <td class="small text-muted" style="max-width: 250px; white-space: normal;">
                                        <?php echo htmlspecialchars($ass['note'] ?? '-'); ?>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale): ?>
                                                <?php if ($statoAss === 'in attesa'): ?>
                                                    <a href="ferie.php?azione_stato=approvato&id=<?php echo $ass['id']; ?>" class="btn btn-outline-success" title="Approva Richiesta"><i class="bi bi-check-lg"></i></a>
                                                    <a href="ferie.php?azione_stato=rifiutato&id=<?php echo $ass['id']; ?>" class="btn btn-outline-danger" title="Rifiuta Richiesta"><i class="bi bi-x-lg"></i></a>
                                                <?php endif; ?>
                                                <a href="ferie.php?azione_stato=eliminato&id=<?php echo $ass['id']; ?>" class="btn btn-outline-dark" onclick="return confirm('Sei sicuro di voler eliminare questa richiesta?');" title="Elimina"><i class="bi bi-trash"></i></a>
                                            <?php else: ?>
                                                <?php if ($uIdAss === $utente_id && $statoAss === 'in attesa'): ?>
                                                    <a href="ferie.php?azione_stato=eliminato&id=<?php echo $ass['id']; ?>" class="btn btn-outline-danger" onclick="return confirm('Vuoi ritirare la tua richiesta?');" title="Ritira Richiesta"><i class="bi bi-trash"></i> Ritira</a>
                                                <?php else: ?>
                                                    <span class="text-muted small">-</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-3">Nessuna richiesta o assenza registrata.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Pannello Controllo Rotazione Weekend / Squadre (SPOSTATO IN BASSO SOTTO LO STORICO) -->
        <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale): ?>
        <div class="card shadow-sm mb-4 bg-white border-start border-4 border-info">
            <div class="card-header bg-light text-dark d-flex justify-content-between align-items-center">
                <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-people-fill text-info"></i> Stato Squadre & Bilancio Equità Weekend del Reparto</h5>
                <span class="badge bg-info text-dark">Controllo Rotazione</span>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">Questa tabella mostra la squadra di appartenenza di ciascun collaboratore e il numero totale di weekend (sabato/domenica) già coperti da ferie/assenze registrate, utile per valutare l'equità nella rotazione.</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Collaboratore</th>
                                <th>Ruolo</th>
                                <th>Squadra</th>
                                <th class="text-center">Weekend Goduti / Coperti</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $utentiTrovatiPerTabella = false;
                            foreach ($mappaUtenti as $idU => $uInfo) {
                                $rU = $uInfo['reparto_id'] ?? '';
                                $ruoloU_raw = trim($uInfo['ruolo'] ?? '');
                                $ruoloU_lower = strtolower(str_replace([' ', '-'], '_', $ruoloU_raw));
                                
                                if ($ruoloU_lower === 'super_admin' || $ruoloU_lower === 'admin' || $ruoloU_lower === 'superadmin' || $ruoloU_lower === 'capo_personale' || $ruoloU_lower === 'capopersonale') {
                                    continue;
                                }

                                if (!$is_super_admin && !$is_capo_personale && !empty($reparto_id_utente) && (string)$rU !== (string)$reparto_id_utente) {
                                    continue;
                                }

                                $utentiTrovatiPerTabella = true;
                                $squadraU = !empty($uInfo['squadra']) ? $uInfo['squadra'] : 'Senza Squadra';
                                $weekendFattiU = $statisticheWeekendFerie[$idU] ?? 0;
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($uInfo['nome'] ?? 'N/D'); ?></strong></td>
                                <td><?php echo htmlspecialchars($uInfo['ruolo'] ?? 'N/D'); ?></td>
                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($squadraU); ?></span></td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo ($weekendFattiU > 2) ? 'warning text-dark' : 'success'; ?> px-3">
                                        <?php echo $weekendFattiU; ?> weekend
                                    </span>
                                </td>
                            </tr>
                            <?php } ?>
                            <?php if (!$utentiTrovatiPerTabella): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">Nessun collaboratore trovato per il reparto corrente.</td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>