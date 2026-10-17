# Sterea Mini-Ședințe

Plugin WordPress pentru rezervări la mini-ședințe foto, pe evenimente cu date fixe (ex. Ședințe foto de Crăciun la Ferma Lipovenesc).

- Evenimente cu zile, interval orar, durată și pauză între ședințe; sloturi generate automat
- Pachete cu preț; formular de rezervare pe site (pachet, zi, oră, date client)
- Emailuri de confirmare, reamintire și notificare; anulare de către client
- Programul zilei, export PDF (program complet sau lista rezervărilor) și Excel
- Update-uri automate din GitHub Releases: noua versiune apare în WordPress > Plugins

## Instalare

Descarcă `sterea-mini.zip` din ultimul [release](https://github.com/mariansterea/sterea-mini/releases/latest) și încarcă-l în WordPress > Plugins > Add New > Upload Plugin. Detalii în `readme.txt`.

## Publicarea unei versiuni noi

1. Crește versiunea în `sterea-mini.php` (antet `Version` și `STM_VERSION`) și în `readme.txt` (`Stable tag` + istoric).
2. Commit + push.
3. Creează un release cu tag `vX.Y.Z` și atașează arhiva `sterea-mini.zip` (folderul `sterea-mini/` la rădăcină).

Site-urile cu pluginul instalat văd update-ul în cel mult 6 ore (sau imediat, cu „Verifică update” din lista de plugin-uri).
