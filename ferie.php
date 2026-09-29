<?php
session_start();
if (!isset($_SESSION['utente'])) {
    header("Location: index.php");
    exit;
}
require_once 'config.php';

// Funzione di supporto personalizzata per le chiamate a Supabase con forzatura dello schema public
function supabase_ferie_request($endpoint, $method = 'GET', $data = null) {
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

if (empty($utente_id)) {
    header("Location: index.php");
    exit;
}

// Se in sessione mancano organizzazione_id o reparto_id, li recuperiamo dal DB per l'utente loggato
if (empty($org_id_utente) || empty($reparto_id_utente)) {
    $resUCorr = supabase_ferie_request("staging_utenti?id=eq.$utente_id&select=organizzazione_id,reparto_id");
    if (!empty($resUCorr) && is_array($resUCorr)) {
        if (empty($org_id_utente)) {
            $org_id_utente = $resUCorr[0]['organizzazione_id'] ?? null;
        }
        if (empty($reparto_id_utente)) {
            $reparto_id_utente = $resUCorr[0]['reparto_id'] ?? null;
        }
    }
}

// 2. Recupero reparti disponibili (filtrati per organizzazione se capo personale)
$reparti_disponibili = [];
$urlReparti = "reparti?select=id,nome_reparto,organizzazione_id&order=nome_reparto.asc";
if ($is_capo_personale && !empty($org_id_utente)) {
    $urlReparti = "reparti?organizzazione_id=eq.$org_id_utente&select=id,nome_reparto,organizzazione_id&order=nome_reparto.asc";
}
$resReparti = supabase_ferie_request($urlReparti);
if (is_array($resReparti) && !isset($resReparti['error'])) {
    $reparti_disponibili = $resReparti;
}

// 3. Gestione Reparto Selezionato
$reparto_selezionato = '';
if ($is_super_admin || $is_capo_personale) {
    if (isset($_GET['reparto_id']) && $_GET['reparto_id'] !== '') {
        $reparto_selezionato = $_GET['reparto_id'];
    }
} else {
    $reparto_selezionato = $reparto_id_utente;
}

// 4. Determinazione nome struttura e reparto
$nome_reparto_selezionato = ($is_super_admin || ($is_capo_personale && $reparto_selezionato === '')) ? "Tutti i Reparti della Struttura" : "Reparto non assegnato";
$nome_struttura_corrente = "Azienda Sanitaria / Struttura";

if (!empty($org_id_utente)) {
    $res_org_filtered = supabase_ferie_request("organizzazioni?id=eq.$org_id_utente&select=nome_struttura");
    if (!empty($res_org_filtered) && is_array($res_org_filtered)) {
        $nome_struttura_corrente = $res_org_filtered[0]['nome_struttura'] ?? $res_org_filtered[0]['NOME_STRUTTURA'] ?? 'Azienda Sanitaria';
    }
}

if ($reparto_selezionato !== '') {
    foreach ($reparti_disponibili as $rep) {
        if ((string)$rep['id'] === (string)$reparto_selezionato) {
            $nome_reparto_selezionato = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
            break;
        }
    }
}

$messaggio = '';
$tipo_alert = '';

// Gestione Sblocco Desiderata personalizzato per ciascun reparto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['azione']) && $_POST['azione'] === 'toggle_sblocco') {
    if ($is_coordinatore || $is_super_admin || $is_capo_personale) {
        $target_reparto_id = trim($_POST['reparto_id'] ?? $reparto_id_utente);
        $nuovo_stato_sblocco = isset($_POST['stato_sblocco']) ? (bool)$_POST['stato_sblocco'] : false;

        if (!isset($_SESSION['sblocco_desiderata_reparto'])) {
            $_SESSION['sblocco_desiderata_reparto'] = [];
        }
        if (!empty($target_reparto_id)) {
            $_SESSION['sblocco_desiderata_reparto'][$target_reparto_id] = $nuovo_stato_sblocco;
        }
        $_SESSION['sblocco_desiderata'] = $nuovo_stato_sblocco;

        header("Location: ferie.php?msg=sblocco_aggiornato&reparto_id=" . urlencode($reparto_selezionato));
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

$reparto_utente_corrente = $reparto_selezionato !== '' ? $reparto_selezionato : $reparto_id_utente;
$sblocco_attivo = isRepartoSbloccato($reparto_utente_corrente);
$giorno_corrente = (int)date('j');

$inserimento_bloccato = false;
if ($giorno_corrente > 16 && $giorno_corrente <= 30 && !$sblocco_attivo && !$is_coordinatore && !$is_super_admin && !$is_capo_personale) {
    $inserimento_bloccato = true;
}

// COSTRUZIONE MAPPA UTENTI CON ISOLAMENTO MULTI-TENANT RIGOROSO
$mappaUtenti = [];

if ($is_super_admin) {
    $urlUtenti = "staging_utenti?select=id,nome,email,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    if ($reparto_selezionato !== '') {
        $urlUtenti = "staging_utenti?reparto_id=eq." . urlencode($reparto_selezionato) . "&select=id,nome,email,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    }
} elseif ($is_capo_personale) {
    // Capo Personale: FILTRO TASSATIVO SULL'ORGANIZZAZIONE_ID
    if (!empty($org_id_utente)) {
        if ($reparto_selezionato !== '') {
            $urlUtenti = "staging_utenti?organizzazione_id=eq.$org_id_utente&reparto_id=eq." . urlencode($reparto_selezionato) . "&select=id,nome,email,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
        } else {
            $urlUtenti = "staging_utenti?organizzazione_id=eq.$org_id_utente&select=id,nome,email,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
        }
    } else {
        $urlUtenti = "";
    }
} else {
    // Coordinatore o altro ruolo: filtra per reparto
    if (!empty($reparto_id_utente)) {
        $urlUtenti = "staging_utenti?reparto_id=eq.$reparto_id_utente&select=id,nome,email,ruolo,qualifica,organizzazione_id,reparto_id,squadra&order=nome.asc";
    } else {
        $urlUtenti = "";
    }
}

if (!empty($urlUtenti)) {
    $resCollabAll = supabase_ferie_request($urlUtenti);
    if (is_array($resCollabAll)) {
        foreach ($resCollabAll as $c) {
            if (!empty($c['id'])) {
                $mappaUtenti[$c['id']] = $c;
            }
        }
    }
}

$listaCollaboratoriDropdown = [];
foreach ($mappaUtenti as $c) {
    $ruoloC_raw = trim($c['ruolo'] ?? '');
    $ruoloC_lower = strtolower(str_replace([' ', '-'], '_', $ruoloC_raw));
    if (in_array($ruoloC_lower, ['super_admin', 'admin', 'superadmin', 'capo_personale', 'capopersonale'])) {
        continue;
    }
    $listaCollaboratoriDropdown[] = $c;
}

$statisticheWeekendFerie = [];
$resAssenzeAll = supabase_ferie_request("assenze?select=utente_id,data_inizio,data_fine,stato,tipo_assenza");
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

                $resAssEsistenti = supabase_ferie_request("assenze?select=*");
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

                $resp = supabase_ferie_request("assenze", 'POST', $datiAssenza);
                if ($resp === false) {
                    $messaggio = "Errore durante il salvataggio dell'assenza nel database.";
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
        $resAssSingle = supabase_ferie_request("assenze?id=eq." . urlencode($id_assenza) . "&select=*");
        if (!empty($resAssSingle) && is_array($resAssSingle)) {
            $assData = $resAssSingle[0];
            
            if (!$is_coordinatore && !$is_super_admin && !$is_capo_personale) {
                if (($assData['utente_id'] ?? '') !== $utente_id || ($assData['stato'] ?? '') !== 'in attesa') {
                    header("Location: ferie.php?msg=permesso_negato");
                    exit;
                }
            }

            // Elimina dalla pianificazione se presente
            $urlDelPian = "pianificazione?utente_id=eq." . urlencode($assData['utente_id']) . "&data_inizio=gte." . urlencode($assData['data_inizio']) . "&data_inizio=lte." . urlencode($assData['data_fine']);
            supabase_ferie_request($urlDelPian, 'DELETE');
        }

        supabase_ferie_request("assenze?id=eq." . urlencode($id_assenza), 'DELETE');

    } else if (in_array($nuovo_stato, ['approvato', 'rifiutato'])) {
        if (!$is_coordinatore && !$is_super_admin && !$is_capo_personale) {
            header("Location: ferie.php?msg=permesso_negato");
            exit;
        }

        supabase_ferie_request("assenze?id=eq." . urlencode($id_assenza), 'PATCH', ['stato' => $nuovo_stato]);

        if ($nuovo_stato === 'approvato') {
            $resAssSingle = supabase_ferie_request("assenze?id=eq." . urlencode($id_assenza) . "&select=*");
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

                $currentDateObj = new DateTime($aData['data_inizio']);
                $endDateObj = new DateTime($aData['data_fine']);
                
                while ($currentDateObj <= $endDateObj) {
                    $dataCorrenteStr = $currentDateObj->format('Y-m-d');

                    $datiPianificazione = [
                        'organizzazione_id' => !empty($org_id_utente) ? $org_id_utente : null,
                        'reparto_id' => !empty($aData['reparto_id']) ? $aData['reparto_id'] : (!empty($reparto_id_utente) ? $reparto_id_utente : null),
                        'utente_id' => $aData['utente_id'],
                        'data_inizio' => $dataCorrenteStr . ' 00:00:00+00',
                        'data_fine' => $dataCorrenteStr . ' 23:59:59+00',
                        'tipo_evento' => $tipoEventoCodice,
                        'stato' => 'Approvato',
                        'note' => $tipoEventoCodice . ' approvata - Blocco turnazione'
                    ];

                    supabase_ferie_request("pianificazione", 'POST', $datiPianificazione);
                    $currentDateObj->modify('+1 day');
                }
            }
        }
    }

    header("Location: ferie.php?msg=aggiornato&reparto_id=" . urlencode($reparto_selezionato));
    exit;
}

// Recupero elenco assenze filtrato per gli utenti autorizzati
$listaAssenze = [];
$idUtentiReparto = array_keys($mappaUtenti);

if ($is_super_admin || $is_capo_personale) {
    if ($reparto_selezionato !== '') {
        if (!empty($idUtentiReparto)) {
            $inQueryList = '(' . implode(',', $idUtentiReparto) . ')';
            $resAssenze = supabase_ferie_request("assenze?utente_id=in." . urlencode($inQueryList) . "&select=*&order=data_inizio.desc");
            if (is_array($resAssenze)) $listaAssenze = $resAssenze;
        }
    } else {
        $resAssenze = supabase_ferie_request("assenze?select=*&order=data_inizio.desc");
        if (is_array($resAssenze)) $listaAssenze = $resAssenze;
    }
} else if ($is_coordinatore) {
    if (!empty($idUtentiReparto)) {
        $inQueryList = '(' . implode(',', $idUtentiReparto) . ')';
        $resAssenze = supabase_ferie_request("assenze?utente_id=in." . urlencode($inQueryList) . "&select=*&order=data_inizio.desc");
        if (is_array($resAssenze)) $listaAssenze = $resAssenze;
    }
} else {
    $resAssenze = supabase_ferie_request("assenze?utente_id=eq.$utente_id&select=*&order=data_inizio.desc");
    if (is_array($resAssenze)) $listaAssenze = $resAssenze;
}

$mesiNomi = [1=>'Gennaio', 2=>'Febbraio', 3=>'Marzo', 4=>'Aprile', 5=>'Maggio', 6=>'Giugno', 7=>'Luglio', 8=>'Agosto', 9=>'Settembre', 10=>'Ottobre', 11=>'Novembre', 12=>'Dicembre'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Ferie e Assenze - PRO-TUR</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8fafc; padding-bottom: 70px; color: #334155; }
        .card { border: none; border-radius: 10px; box-shadow: 0 4px 20px -2px rgba(0, 0, 0, 0.05); background: #ffffff; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="dashboard.php"><i class="bi bi-hospital"></i> PRO-TUR | Ferie e Assenze</a>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="planner.php">Planner Mensile</a></li>
                    <li class="nav-item"><a class="nav-link" href="turni.php">Assegnazione Turni</a></li>
                    <li class="nav-item"><a class="nav-link active" href="ferie.php">Ferie e Assenze</a></li>
                    <li class="nav-item"><a class="nav-link text-danger" href="logout.php">Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4">
        
        <!-- Header con Filtro Reparto (uguale a turni.php e planner.php) -->
        <div class="card shadow-sm mb-4 p-3">
            <div class="row align-items-center g-3">
                <div class="col-md-7">
                    <h2 class="mb-1 fw-bold text-dark d-flex align-items-center gap-2">
                        <i class="bi bi-calendar-minus text-primary"></i> <?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? 'Gestione Ferie, Assenze ed Equità Weekend' : 'Le tue Ferie e Permessi'; ?>
                    </h2>
                    <span class="small text-muted">
                        <i class="bi bi-building"></i> Struttura: <strong class="text-dark"><?php echo htmlspecialchars($nome_struttura_corrente); ?></strong> | 
                        Reparto: <strong class="text-dark"><?php echo htmlspecialchars($nome_reparto_selezionato); ?></strong>
                    </span>
                </div>
                <div class="col-md-5 text-md-end">
                    <?php if (($is_super_admin || $is_capo_personale) && !empty($reparti_disponibili)) { ?>
                        <form method="GET" action="ferie.php" class="d-inline-block">
                            <select name="reparto_id" class="form-select form-select-sm" onchange="this.form.submit()">
                                <option value="">Tutti i Reparti della Struttura</option>
                                <?php foreach ($reparti_disponibili as $rep) { 
                                    $labelRep = $rep['nome_reparto'] ?? $rep['nome'] ?? 'Reparto';
                                ?>
                                    <option value="<?php echo $rep['id']; ?>" <?php echo ($reparto_selezionato !== '' && (string)$rep['id'] === (string)$reparto_selezionato) ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelRep); ?></option>
                                <?php } ?>
                            </select>
                        </form>
                    <?php } ?>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'aggiornato'): ?>
            <div class="alert alert-success py-2 small shadow-sm" role="alert">Stato aggiornato con successo.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'sblocco_aggiornato'): ?>
            <div class="alert alert-info py-2 small shadow-sm" role="alert">Stato sblocco desiderata aggiornato con successo per il reparto.</div>
        <?php endif; ?>
        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'permesso_negato'): ?>
            <div class="alert alert-danger py-2 small shadow-sm" role="alert">Operazione non autorizzata.</div>
        <?php endif; ?>

        <?php if (!empty($messaggio)) { ?>
            <div class="alert alert-<?php echo $tipo_alert; ?> py-2 small fw-bold shadow-sm" role="alert">
                <i class="bi bi-info-circle-fill"></i> <?php echo htmlspecialchars($messaggio); ?>
            </div>
        <?php } ?>

        <!-- Pannello Coordinatore/Capo Personale: Gestione Sblocco Desiderata -->
        <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale): ?>
        <div class="card shadow-sm mb-4 bg-white border-start border-4 border-warning p-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                <div>
                    <h5 class="mb-1 fs-6 fw-bold"><i class="bi bi-calendar-check text-warning"></i> Controllo Scadenza Desiderata (Scadenza: 16 del mese)</h5>
                    <p class="text-muted small mb-0">Gestisci lo sblocco per il reparto selezionato. Dal 17 al 30 è attivo il blocco standard.</p>
                </div>
                <form method="POST" action="ferie.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>" class="d-flex align-items-center gap-2">
                    <input type="hidden" name="azione" value="toggle_sblocco">
                    <input type="hidden" name="reparto_id" value="<?php echo htmlspecialchars($reparto_utente_corrente); ?>">
                    <input type="hidden" name="stato_sblocco" value="<?php echo $sblocco_attivo ? '0' : '1'; ?>">
                    <span class="small fw-bold text-<?php echo $sblocco_attivo ? 'success' : 'secondary'; ?>">
                        <?php echo $sblocco_attivo ? 'Sblocco Attivo (fino al 30)' : 'Blocco Standard Attivo'; ?>
                    </span>
                    <button type="submit" class="btn btn-sm btn-<?php echo $sblocco_attivo ? 'outline-danger' : 'outline-success'; ?> fw-bold">
                        <?php echo $sblocco_attivo ? 'Disattiva Sblocco' : 'Sblocca Fino al 30'; ?>
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Form Inserimento Richiesta Assenza -->
        <div class="card shadow-sm mb-4 bg-white p-3">
            <div class="card-header bg-white pb-2 px-0 border-bottom">
                <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-plus-circle"></i> <?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? 'Inserisci Nuova Richiesta / Assenza Collaboratore' : 'Inoltra Nuova Richiesta di Ferie o Permesso'; ?></h5>
            </div>
            <div class="card-body px-0">
                <?php if ($inserimento_bloccato): ?>
                    <div class="alert alert-danger mb-0" role="alert">
                        <i class="bi bi-lock-fill"></i> <strong>Termine scaduto:</strong> Il termine per l'inserimento delle desiderata era fissato al 16 del mese. Attualmente le richieste sono bloccate.
                    </div>
                <?php else: ?>
                    <form method="POST" action="ferie.php?reparto_id=<?php echo urlencode($reparto_selezionato); ?>">
                        <input type="hidden" name="azione" value="nuova_assenza">
                        <div class="row g-3">
                            <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale): ?>
                                <div class="col-md-3">
                                    <label for="collaboratore_id" class="form-label small fw-bold">Collaboratore</label>
                                    <select class="form-select form-select-sm" id="collaboratore_id" name="collaboratore_id" required>
                                        <option value="">-- Seleziona --</option>
                                        <?php foreach ($listaCollaboratoriDropdown as $c) { 
                                            $squadraTxt = !empty($c['squadra']) ? ' [Squadra: ' . $c['squadra'] . ']' : '';
                                        ?>
                                            <option value="<?php echo $c['id']; ?>">
                                                <?php echo htmlspecialchars($c['nome']); ?> (<?php echo htmlspecialchars($c['ruolo'] ?? $c['qualifica'] ?? 'N/D'); ?><?php echo $squadraTxt; ?>)
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                            <?php else: ?>
                                <div class="col-md-4">
                            <?php endif; ?>
                                <label for="tipo" class="form-label small fw-bold">Tipo Assenza</label>
                                <select class="form-select form-select-sm" id="tipo" name="tipo" required>
                                    <option value="ferie">Ferie</option>
                                    <option value="permesso">Permesso</option>
                                    <option value="malattia">Malattia</option>
                                    <option value="104">Legge 104</option>
                                    <option value="riposo">Riposo Compensativo</option>
                                </select>
                            </div>
                            <div class="col-md-<?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? '2' : '3'; ?>">
                                <label for="data_inizio" class="form-label small fw-bold">Data Inizio</label>
                                <input type="date" class="form-control form-control-sm" id="data_inizio" name="data_inizio" required>
                            </div>
                            <div class="col-md-<?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? '2' : '3'; ?>">
                                <label for="data_fine" class="form-label small fw-bold">Data Fine</label>
                                <input type="date" class="form-control form-control-sm" id="data_fine" name="data_fine" required>
                            </div>
                            <div class="col-md-<?php echo ($is_coordinatore || $is_super_admin || $is_capo_personale) ? '2' : '2'; ?>">
                                <label class="form-label small fw-bold d-block">&nbsp;</label>
                                <button type="submit" class="btn btn-primary btn-sm w-100 fw-bold"><i class="bi bi-send-fill"></i> Invia</button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabella Elenco Assenze -->
        <div class="card shadow-sm bg-white mb-5">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fs-6 fw-bold"><i class="bi bi-list-check"></i> Elenco Richieste e Assenze Registrate</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-dark">
                            <tr>
                                <th>Collaboratore</th>
                                <th>Tipo</th>
                                <th>Dal</th>
                                <th>Al</th>
                                <th>Stato</th>
                                <th>Note / Conflitti</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($listaAssenze)) { ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">Nessuna richiesta di assenza trovata.</td>
                                </tr>
                            <?php } else { ?>
                                <?php foreach ($listaAssenze as $ass) { 
                                    $uAssId = $ass['utente_id'] ?? '';
                                    $nomeUtenteAss = $mappaUtenti[$uAssId]['nome'] ?? 'Utente Sconosciuto';
                                    $tipoAss = $ass['tipo_assenza'] ?? $ass['tipo'] ?? 'ferie';
                                    $dInz = $ass['data_inizio'] ?? '';
                                    $dFin = $ass['data_fine'] ?? '';
                                    $statoAss = strtolower($ass['stato'] ?? 'in attesa');
                                    $noteAss = $ass['note'] ?? '';

                                    // Se l'utente non è nella mappa (es. fuori reparto/struttura), saltalo per sicurezza multi-tenant
                                    if (!isset($mappaUtenti[$uAssId]) && !$is_super_admin && !$is_capo_personale) {
                                        continue;
                                    }
                                ?>
                                    <tr>
                                        <td class="fw-bold text-dark"><?php echo htmlspecialchars($nomeUtenteAss); ?></td>
                                        <td><span class="badge bg-secondary text-uppercase" style="font-size: 0.7rem;"><?php echo htmlspecialchars($tipoAss); ?></span></td>
                                        <td><?php echo htmlspecialchars($dInz); ?></td>
                                        <td><?php echo htmlspecialchars($dFin); ?></td>
                                        <td>
                                            <?php if ($statoAss === 'approvato') { ?>
                                                <span class="badge bg-success">Approvato</span>
                                            <?php } elseif ($statoAss === 'rifiutato') { ?>
                                                <span class="badge bg-danger">Rifiutato</span>
                                            <?php } else { ?>
                                                <span class="badge bg-warning text-dark">In attesa</span>
                                            <?php } ?>
                                        </td>
                                        <td class="text-muted small" style="max-width: 250px;"><?php echo htmlspecialchars($noteAss); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1">
                                                <?php if ($is_coordinatore || $is_super_admin || $is_capo_personale): ?>
                                                    <?php if ($statoAss !== 'approvato'): ?>
                                                        <a href="ferie.php?azione_stato=approvato&id=<?php echo $ass['id']; ?>&reparto_id=<?php echo urlencode($reparto_selezionato); ?>" class="btn btn-success btn-sm py-0 px-2" title="Approva"><i class="bi bi-check"></i></a>
                                                    <?php endif; ?>
                                                    <?php if ($statoAss !== 'rifiutato'): ?>
                                                        <a href="ferie.php?azione_stato=rifiutato&id=<?php echo $ass['id']; ?>&reparto_id=<?php echo urlencode($reparto_selezionato); ?>" class="btn btn-warning btn-sm py-0 px-2" title="Rifiuta"><i class="bi bi-x"></i></a>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                                
                                                <?php if (($is_coordinatore || $is_super_admin || $is_capo_personale) || ($uAssId === $utente_id && $statoAss === 'in attesa')): ?>
                                                    <a href="ferie.php?azione_stato=eliminato&id=<?php echo $ass['id']; ?>&reparto_id=<?php echo urlencode($reparto_selezionato); ?>" class="btn btn-outline-danger btn-sm py-0 px-2" onclick="return confirm('Sei sicuro di voler eliminare questa richiesta?');" title="Elimina"><i class="bi bi-trash"></i></a>
                                                <?php endif; ?>
                                            </div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
