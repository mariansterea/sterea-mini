=== Sterea Mini-Ședințe ===
Requires at least: 6.0
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later

Rezervări pentru mini-ședințe foto, pe evenimente cu date fixe.

== Ce face ==

* Evenimente (ex. „Toamna la fermă”, „Ședințe foto de Crăciun”) cu imagine, descriere, locație, zile și intervale orare.
* Sloturi generate automat: durată + pauză (ex. 30 + 10 min → 10:00, 10:40, 11:20…).
* Pachete la alegere, cu preț și descriere (ex. ședință 30 min / ședință + album).
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
2. Mini-ședințe → Setări: emailuri de notificare, telefon, informații utile.
3. Mini-ședințe → Adaugă eveniment. Puneți [sterea_mini] într-o pagină.
4. Al doilea site: instalați același plugin, în Setări alegeți „Afișare de pe alt site” și completați adresa site-ului principal. Puneți [sterea_mini] într-o pagină.

== Istoric versiuni ==

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
