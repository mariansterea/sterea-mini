=== Sterea Mini-Ședințe ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.8.3
License: GPLv2 or later

Rezervări pentru mini-ședințe foto, pe evenimente cu date fixe.

== Ce face ==

* Evenimente (ex. „Toamna la fermă”, „Ședințe foto de Crăciun”) cu imagine, descriere, locație, zile și intervale orare.
* Sloturi generate automat: durată + pauză (ex. 30 + 10 min → 10:00, 10:40, 11:20…).
* Pachete la alegere, cu preț și descriere (ex. ședință 30 min / ședință + album).
* Calendar vizual de disponibilitate: click pe zile, „Adaugă interval de zile” (de la–până la, zile din săptămână, ore), ore blocate manual, zile libere globale.
* Cupoane de reducere: procent sau sumă fixă, pe evenimente/pachete, limită totală și per client, perioadă, doar pentru anumiți clienți (după email).
* Carduri cadou, direct din formularul de rezervare („Oferă cadou o ședință foto”): cardul personalizat (JPG + PDF, cu cod unic și cod de bare) pleacă pe email după confirmarea plății sau imediat, către cumpărător și, opțional, către destinatar, la data aleasă. Codul se folosește la rezervare, în câmpul de cod; rezervarea anulată eliberează cardul. Imaginea-model a cardului se poate pune în orice pagină cu [sterea_mini_card_model].
* Google Calendar: fiecare rezervare apare automat în calendarul ales (nume, telefon, email, pachet, observații), verde = confirmată, galben = în așteptare; se mută odată cu rezervarea și dispare la anulare.
* Dashboard: rezervări confirmate / în așteptare, ore libere, grad de ocupare, încasări estimate, ocuparea pe zile, următoarele rezervări.
* Formular pe site: pachet → zi → oră → date client. Fără plată online.
* O oră nu poate fi rezervată de două ori (blocare la nivel de bază de date).
* Emailuri: confirmare către client (cu „Adaugă în calendar” și link de anulare), notificare către voi, reamintire cu o zi înainte.
* Clientul își poate anula singur rezervarea; ora se eliberează automat.
* Rezervări adăugate manual (telefonic), confirmare manuală opțională.
* „Programul zilei”: lista clienților pe ore, gata de printat, plus „Descarcă PDF” (ziua sau toate zilele).
* Lista „Rezervări”: butoane „Descarcă PDF” și „Descarcă Excel” (.xlsx), cu filtrele active.
* Două site-uri: rezervările se salvează pe site-ul principal (fotograful), iar al doilea site (lipovenesc.ro) afișează același program și trimite rezervările acolo.

== Instalare ==

1. Site principal: Module → Adaugă modul → Încarcă modul → sterea-mini.zip → Activează. Update-urile vin apoi automat din GitHub.
2. Mini-ședințe → Setări: emailuri de notificare, telefon, informații utile. La „Emailuri”, completează „Adresa expeditorului” cu o căsuță reală de pe domeniu (ex. marianst@mariansterea.ro) și apasă „Trimite un email de test”.
3. Mini-ședințe → Adaugă eveniment. Puneți [sterea_mini] într-o pagină.
4. Al doilea site: instalați același plugin, în Setări alegeți „Afișare de pe alt site” și completați adresa site-ului principal. Puneți [sterea_mini] într-o pagină.

== Google Calendar: configurare (o singură dată, ~10 minute) ==

1. În Google Calendar (pe calculator): lângă „Alte calendare” apasă „+” → „Creează un calendar nou” → numele „Ședințe foto” → Creează. Dă-i o culoare din meniul calendarului (ex. auriu).
2. Mergi la https://console.cloud.google.com/ (cu contul tău Google) → creează un proiect (ex. „sterea-mini”).
3. „APIs & Services” → „Enable APIs and services” → caută „Google Calendar API” → Enable.
4. „IAM & Admin” → „Service accounts” → „Create service account” → nume „sterea-mini” → Create → Done.
5. Deschide contul de serviciu → tab „Keys” → „Add key” → „Create new key” → JSON → se descarcă un fișier .json. Copiază adresa lui de email (…@….iam.gserviceaccount.com).
6. Înapoi în Google Calendar: calendarul „Ședințe foto” → „Setări și partajare” → „Partajează cu anumite persoane” → Adaugă adresa contului de serviciu, cu „Efectuează modificări la evenimente”.
7. Tot acolo, jos, la „Integrează calendarul”, copiază „ID calendar”.
8. WordPress → Mini-ședințe → Setări → Google Calendar: bifează sincronizarea, lipește ID-ul calendarului și conținutul fișierului .json → Salvează → „Testează conexiunea” → „Pune în calendar rezervările viitoare”.

== Carduri cadou ==

1. Mini-ședințe → Setări → Carduri cadou: bifează „Arată opțiunea «Oferă cadou» în formularul de rezervare”, alege când pleacă cardul (după confirmarea plății sau imediat), scrie instrucțiunile de plată și pagina de rezervare.
2. În formularul de rezervare apare, la primul pas, alegerea „Rezerv pentru noi” / „Oferă cadou o ședință foto de Crăciun”. Pentru o pagină dedicată: [sterea_mini cadou="1"] sau [sterea_mini_card_cadou]. Pe al doilea site (lipovenesc.ro) merge la fel: comanda ajunge pe site-ul principal, care decide dacă opțiunea apare și cum pleacă cardul.
   Se pot oferi doar pachetele cu preț, la evenimentele deschise care mai au zile în program (chiar dacă orele sunt ocupate).
   Imaginea-model a cardului (date de exemplu) pentru pagini și articole: [sterea_mini_card_model stil="craciun"] sau stil="negru"; opțional link="https://…" (imaginea devine link), latime="500" (lățime maximă în px) și alt="…".
3. Comenzile apar în Mini-ședințe → Carduri cadou. Varianta „după plată”: „Marchează plătit și trimite”. Varianta „imediat”: cardul pleacă la comandă, tu apeși „Am primit plata” când intră banii. Cardul se generează din șablonul din assets/img (Crăciun = verde, restul = negru) și pleacă pe email cu PDF atașat.
4. Destinatarul rezervă normal și scrie codul (ex. MS-2611-K7PQ) la pasul „Confirmare”; valoarea se scade. Cardul devine „Folosit”; dacă rezervarea se anulează, redevine valabil.
5. Carduri vândute direct (cash, la studio): „Adaugă card cadou”, completezi, pui status „Plătit, valabil”, salvezi, apoi „Regenerează fișierele” și „Retrimite”.
Are nevoie de extensia PHP GD cu suport TrueType (prezentă pe majoritatea hostingurilor).

== Istoric versiuni ==

= 1.8.3 (2026-11-03) =
* Modelul cardului de sub „Oferă cadou” e acum mare, pe toată lățimea opțiunii, cu textul „Așa arată cardul cadou” dedesubt.

= 1.8.2 (2026-11-03) =
* Lista cu pașii formularului nu mai primește numerotare și spații de la temele care stilizează orice listă din conținut (ex. Divi pe lipovenesc.ro).

= 1.8.1 (2026-11-02) =
* Cardul cadou este acum în formularul de rezervare: la pasul 1, „Rezerv pentru noi” / „Oferă cadou o ședință foto de Crăciun”; pașii devin Pachet → Cardul cadou (pentru, din partea, ocazie, mesaj, email destinatar, dată) → Datele tale → Confirmare. [sterea_mini cadou="1"] pornește direct pe cadou. Formularul separat a fost scos.
* La pasul „Cardul cadou” se vede modelul cardului (Crăciun sau negru, după eveniment și ocazie), iar sub alegerea de la pasul 1 apare o miniatură. După comandă, dacă cardul pleacă imediat, pagina arată chiar cardul generat.
* Shortcode nou [sterea_mini_card_model stil="craciun|negru" link="" latime="" alt=""]: imaginea-model a cardului (WebP 1400/700 px, fără ramă, colțuri transparente), oriunde, inclusiv pe al doilea site.
* Cadoul merge și când evenimentul nu mai are ore libere („Rezerv pentru noi” apare dezactivat); nu se poate oferi pentru un eveniment închis sau încheiat. Data de trimitere către destinatar trebuie să fie în viitor și cel târziu în ultima zi cu ședințe.
* Pe card, numele lungi și mesajele lungi se scriu mai mic ca să încapă (nu mai sunt tăiate la ~18 caractere).
* Al doilea site (lipovenesc.ro): opțiunea cadou și modul de trimitere vin de pe site-ul principal; erorile ajung lângă câmpul potrivit; linkurile cardului sunt cele de pe site-ul principal; limita de comenzi se aplică pe vizitator (X-STM-Client-IP), cu un plafon pe server. Verificarea cupoanelor trimite și ea IP-ul vizitatorului.
* Cache (LiteSpeed etc.): programul și setările de cadou nu se mai păstrează în cache (no-store + semnal pentru LiteSpeed, și pe al doilea site); descărcarea cardului nu se pune în cache.
* Setare nouă: cardul pleacă după confirmarea plății sau imediat după comandă (cu detaliile de plată în același email; „Am primit plata” în listă și în pagina cardului).
* Emailuri: numele și adresa expeditorului se setează din Setări (nu mai apare „WordPress”); adresa devine și expeditorul tehnic (Return-Path), altfel releul de email al hostingului respinge mesajele cu „550 Invalid sender”. Bifă nouă: „Folosește această adresă ca expeditor pentru toate emailurile site-ului” (formulare de contact, WordPress). Buton „Trimite un email de test” (arată și eroarea) și jurnalul ultimelor 30 de emailuri.

= 1.8.0 (2026-11-02) =
* Carduri cadou: formular public [sterea_mini_card_cadou] (ședință dintr-un eveniment sau sumă liberă; pentru cine, din partea cui, ocazie, mesaj; email destinatar și dată de trimitere opționale), cod unic MS-LLAA-XXXX, instrucțiuni de plată pe email, aprobare din admin („Marchează plătit și trimite”), card generat pe server (JPG 8,5×3,5 inch la 300 dpi + PDF, cod de bare Code 128) din șablonul grafic, email către cumpărător și destinatar (imediat sau programat, prin cron), listă în admin cu statusuri (în așteptare, plătit, folosit, expirat, anulat), filtre, retrimitere și regenerare.
* Folosire la rezervare: codul cardului merge în același câmp ca un cupon („Ai un cod de reducere sau un card cadou?”); valoarea rămasă se scade, cardul devine „Folosit”, iar anularea rezervării îl face din nou valabil. Un card pe un eveniment cu date fixe expiră cel târziu în ultima zi a evenimentului.
* Setări → Carduri cadou: activare, instrucțiuni de plată, sumă minimă, valabilitate (luni), textul de pe card, pagina de rezervare, mesajul de după comandă.
* Fișierele cardurilor stau în uploads/stm-giftcards (blocat la acces direct) și se descarcă doar prin link cu token.


= 1.7.1 (2026-10-27) =
* Reparat: filtrele din Dashboard și Programul zilei dădeau „Cannot load stm-dashboard” când pagina era deschisă din meniul lateral (formularele trimit acum la adresa corectă).

= 1.7.0 (2026-10-25) =
* Sincronizare cu Google Calendar printr-un cont de serviciu: rezervare nouă → eveniment cu numele, telefonul, emailul, pachetul, reducerea și observațiile clientului; mutare / confirmare → actualizare (verde = confirmată, galben = în așteptare); anulare (de client sau din admin) → evenimentul dispare. Setări → Google Calendar, cu „Testează conexiunea” și „Pune în calendar rezervările viitoare”.

= 1.6.1 (2026-10-25) =
* Lista de evenimente: coloana „Zile” arată un rezumat (număr de zile, interval, ore), nu toate zilele.

= 1.6.0 (2026-10-25) =
* Disponibilitate: calendar vizual în eveniment (click pe zi = pornit/oprit, orele se schimbă din calendar, „la toate zilele”), „Adaugă interval de zile” cu zile din săptămână și ore; zilele libere din Setări (ex. Crăciun, și „în fiecare an”) nu apar în formular.
* Programul zilei: „Blochează” o oră liberă (dispare din formular, fără rezervare) și „+ Rezervare manuală” cu evenimentul, ziua și ora deja completate.
* Cupoane: meniu nou; cod, procent sau sumă fixă, pe anumite evenimente sau pachete, limită totală și per client, valabilitate, cupon personal (doar anumite emailuri), activ/oprit, contor folosiri. În formular: „Ai un cod de reducere?” la pasul Confirmare, cu totalul recalculat pe server. Reducerea apare în rezervare, emailuri, Programul zilei și exporturi.
* Dashboard (prima pagină din meniu): cifre cheie, ocuparea pe zile cu click către Programul zilei, următoarele rezervări, butoane rapide.
* Rezervări: butoane „Confirmă / Anulează / Reactivează” direct în listă și acțiuni în masă.

= 1.5.1 (2026-10-25) =
* Culoarea de accent implicită este auriul site-ului (#be9656); setarea veche verde se trece automat pe auriu, orice altă culoare aleasă rămâne.
* Etichetele și butoanele formularului nu mai preiau majusculele temei.

= 1.5.0 (2026-10-25) =
* Formular nou de rezervare în 4 pași (Pachet → Data și ora → Datele tale → Confirmare), cu bară laterală de progres și rezumatul alegerilor, calendar lunar și pas de confirmare înainte de trimitere.
* Ecran de succes cu „Adaugă în Google Calendar” și link către hartă.
* Scriptul formularului este exclus de la „delay/defer JS” (LiteSpeed Cache, WP Rocket): formularul se încarcă imediat, nu doar după ce vizitatorul dă scroll.
* Backend-ul, API-ul și datele rămân neschimbate; același shortcode [sterea_mini].

= 1.4.0 (2026-10-17) =
* Zilele sunt grupate pe luni, ca chip-uri compacte (3 pe rând pe telefon); lunile se pot strânge, iar prima cu locuri libere e deschisă.
* „Lista rezervărilor (PDF)” afișează doar zilele cu rezervări.
* „Descarcă Excel” și în „Programul zilei”.

= 1.3.0 (2026-10-17) =
* Update-uri automate din GitHub (github.com/mariansterea/sterea-mini): noua versiune apare în Plugins ca la orice plugin, cu „Verifică update” în rândul pluginului.

= 1.2.0 (2026-10-17) =
* „Teme” se numesc acum „Evenimente (ședințe)” peste tot în interfață (meniu, coloane, filtre, exporturi, emailuri).
* Submeniul „Re-Order” (Post Types Order) nu mai apare sub Mini-ședințe.

= 1.1.1 (2026-10-17) =
* „Lista rezervărilor (PDF)”: document compact doar cu rezervările (fără orele libere), grupat pe zile, cu totaluri; din „Programul zilei” și din lista „Rezervări”.
* Export Excel fără dependența de ZipArchive (merge pe orice hosting).

= 1.1.0 (2026-10-17) =
* Export PDF (document A4, câte o pagină pe zi, totaluri) din „Programul zilei” și din lista „Rezervări”.
* Export Excel (.xlsx) al rezervărilor din lista „Rezervări”, respectând filtrele (eveniment, status, viitoare/trecute).
* Formularul de rezervare păstrează datele completate la orice re-randare și marchează cu roșu câmpurile lipsă sau greșite.
* Delimitare cromatică: pachetele au culori proprii și indicator de selecție; „Alege ziua” și „Alege ora” sunt într-un panou separat.

= 1.0.0 =
* Prima versiune.
