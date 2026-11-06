# Hervyho blokátor komentářů

WordPress plugin, který na celém webu vypne komentáře, pingbacky a trackbacky, skryje je v administraci a umí existující komentáře hromadně uzavřít a smazat.

## Funkce

- **Web:** komentáře zavřené, existující komentáře a formuláře skryté (klasické i blokové šablony), widget a blok „Poslední komentáře“ vypnuté.
- **Administrace:** skryté menu Komentáře, Nastavení → Diskuze, ikona v horní liště, komentáře na nástěnce, panel Diskuze v editoru, sloupec komentářů ve výpisech a volba v rychlých úpravách.
- **API a feedy:** REST API komentářů zablokované pro nepřihlášené, vypnuté pingbacky přes XML-RPC, hlavička X-Pingback a RSS feedy komentářů.
- **Výjimky:** u vybraných typů obsahu (např. produkty WooCommerce) zůstanou komentáře zachované.
- **Čistka:**
  - *Uzavřít komentáře* nastaví u všech existujících obsahů komentáře a pingbacky na „zavřeno“ a změní i výchozí nastavení pro nové obsahy.
  - *Smazat komentáře* trvale smaže komentáře, pingbacky a trackbacky včetně spamu, koše a metadat a přepočítá počty komentářů. Jiné typy záznamů v tabulce komentářů (recenze a poznámky k objednávkám WooCommerce, logy Action Scheduleru) nechá být.

Nastavení: **Nastavení → Blokátor komentářů**.

## Instalace

1. Zabalte složku do ZIPu tak, aby v kořeni archivu byla složka `hervyho-blokator-komentaru/` s těmito soubory.
2. Ve WordPressu: *Pluginy → Instalace pluginů → Nahrát plugin*.
3. Aktivujte plugin a v nastavení případně zvolte výjimky.
4. Zálohujte databázi a spusťte čistku.

## Deaktivace a odinstalace

- Po deaktivaci se blokování a skrývání vypne. Komentáře, které jste uzavřeli čistkou, zůstanou zavřené, protože je to uložené v databázi.
- Při smazání pluginu se odstraní jen jeho nastavení (`hbk_settings`).

## Požadavky

WordPress 5.9+, PHP 7.4+. Otestováno na WordPressu 7.1.4 a PHP 8.3.
