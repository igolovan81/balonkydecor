SET NAMES utf8mb4;

-- ============================================================
-- Demo data for BalonkyDecor
-- Run AFTER schema.sql. Safe to re-run (uses INSERT IGNORE /
-- ON DUPLICATE KEY UPDATE so it won't duplicate rows).
-- ============================================================

-- ------------------------------------------------------------
-- Categories
-- ------------------------------------------------------------
INSERT IGNORE INTO `categories` (`id`, `slug`, `sort_order`) VALUES
  (1, 'narozeniny',  1),
  (2, 'svatba',      2),
  (3, 'detske-party',3),
  (4, 'firemni-akce',4);

INSERT INTO `category_t` (`category_id`, `lang_code`, `name`, `description`) VALUES
  (1,'cs','Narozeniny',       'Balónky a dekorace na narozeninové oslavy.'),
  (1,'sk','Narodeniny',       'Balóny a dekorácie na narodeninovú oslavu.'),
  (1,'en','Birthday',         'Balloons and decorations for birthday parties.'),
  (1,'uk','День народження',  'Кулі та декорації для святкування дня народження.'),
  (1,'ru','День рождения',    'Шары и декорации для праздника дня рождения.'),

  (2,'cs','Svatba',           'Elegantní balónkové dekorace pro váš velký den.'),
  (2,'sk','Svadba',           'Elegantné balónové dekorácie pre váš veľký deň.'),
  (2,'en','Wedding',          'Elegant balloon decorations for your big day.'),
  (2,'uk','Весілля',          'Елегантні кулькові декорації для вашого особливого дня.'),
  (2,'ru','Свадьба',          'Элегантные шаровые декорации для вашего особого дня.'),

  (3,'cs','Dětské party',     'Pohádkové dekorace pro nejmenší oslavence.'),
  (3,'sk','Detské párty',     'Rozprávkové dekorácie pre najmenších oslavencov.'),
  (3,'en','Kids Parties',     'Magical decorations for the little ones.'),
  (3,'uk','Дитячі свята',     'Казкові декорації для найменших іменинників.'),
  (3,'ru','Детские праздники','Сказочные декорации для маленьких именинников.'),

  (4,'cs','Firemní akce',     'Profesionální výzdoba pro firemní události a konference.'),
  (4,'sk','Firemné akcie',    'Profesionálna výzdoba pre firemné udalosti a konferencie.'),
  (4,'en','Corporate Events', 'Professional décor for corporate events and conferences.'),
  (4,'uk','Корпоративи',      'Професійне оформлення для корпоративних заходів.'),
  (4,'ru','Корпоративы',      'Профессиональное оформление корпоративных мероприятий.')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- ------------------------------------------------------------
-- Products
-- ------------------------------------------------------------
INSERT IGNORE INTO `products` (`id`, `category_id`, `sku`, `price`, `stock_type`, `is_active`, `sort_order`) VALUES
  (1, 1, 'NAR-SADA-KLASIK',  890.00, 'unlimited', 1, 1),
  (2, 1, 'NAR-SADA-PREMIUM', 1490.00,'unlimited', 1, 2),
  (3, 2, 'SVA-OBLOUK-BILY',  2490.00,'unlimited', 1, 1),
  (4, 2, 'SVA-STROP-ELEGANCE',1890.00,'unlimited',1, 2),
  (5, 3, 'DET-JEDNROZEC',    1290.00,'unlimited', 1, 1),
  (6, 3, 'DET-SUPERHRDINA',  1190.00,'unlimited', 1, 2),
  (7, 4, 'FIR-VSTUP-ARCH',   3490.00,'unlimited', 1, 1),
  (8, 4, 'FIR-STOLEK-DEKO',   990.00,'unlimited', 1, 2);

INSERT INTO `product_t` (`product_id`, `lang_code`, `name`, `description`) VALUES
  -- Product 1
  (1,'cs','Narozeninová sada Classic',   '<p>Kompletní sada balónků pro narozeninovou oslavu. Obsahuje 20 latexových balónků v barvách dle výběru, balónkový věnec a personalizovaný nápis s číslem.</p>'),
  (1,'sk','Narodeninová sada Classic',   '<p>Kompletná sada balónov pre narodeninová oslava. Obsahuje 20 latexových balónov v farbách podľa výberu, balónový veniec a personalizovaný nápis s číslom.</p>'),
  (1,'en','Birthday Set Classic',        '<p>Complete balloon set for a birthday party. Includes 20 latex balloons in colours of your choice, a balloon garland and a personalised number sign.</p>'),
  (1,'uk','Набір на День Народження Classic','<p>Повний набір кульок для святкування дня народження. Включає 20 латексних кульок вибраних кольорів, гірлянду з кульок та персоналізований напис з цифрою.</p>'),
  (1,'ru','Набор на День Рождения Classic','<p>Полный набор шаров для праздника. Включает 20 латексных шаров выбранных цветов, гирлянду из шаров и персонализированную цифру.</p>'),
  -- Product 2
  (2,'cs','Narozeninová sada Premium',   '<p>Luxusní balónková výzdoba pro nezapomenutelnou oslavu. Velký balónkový oblouk, čísla z fóliových balónků, dekorovaný stůl a fotokoutek.</p>'),
  (2,'sk','Narodeninová sada Premium',   '<p>Luxusná balónová výzdoba pre nezabudnuteľnú oslavu. Veľký balónový oblúk, čísla z fóliových balónov, ozdobený stôl a fotokútik.</p>'),
  (2,'en','Birthday Set Premium',        '<p>Luxury balloon decoration for an unforgettable party. Large balloon arch, foil number balloons, decorated table and photo corner.</p>'),
  (2,'uk','Набір на День Народження Premium','<p>Розкішне оформлення кульками для незабутнього свята. Велика арка з кульок, фольговані цифри, прикрашений стіл та фотозона.</p>'),
  (2,'ru','Набор на День Рождения Premium','<p>Роскошное оформление шарами для незабываемого праздника. Большая арка из шаров, фольгированные цифры, украшенный стол и фотозона.</p>'),
  -- Product 3
  (3,'cs','Svatební oblouk Bílý sen',    '<p>Nádherný bílý balónkový oblouk z organické latexové výzdoby. Ideální jako pozadí pro svatební focení nebo jako vstupní brána. Výška 2,5 m, šířka 3 m.</p>'),
  (3,'sk','Svadobný oblúk Biely sen',    '<p>Nádherný biely balónový oblúk z organickej latexovej výzdoby. Ideálny ako pozadie pre svadobné fotenie alebo ako vstupná brána. Výška 2,5 m, šírka 3 m.</p>'),
  (3,'en','Wedding Arch White Dream',    '<p>Beautiful white organic latex balloon arch. Ideal as a backdrop for wedding photos or as an entrance gate. Height 2.5 m, width 3 m.</p>'),
  (3,'uk','Весільна арка Білий сон',     '<p>Чудова біла арка з органічного латексу. Ідеальна як тло для весільної фотосесії або як вхідна арка. Висота 2,5 м, ширина 3 м.</p>'),
  (3,'ru','Свадебная арка Белая мечта',  '<p>Прекрасная белая органическая арка из латекса. Идеальна как фон для свадебной фотосессии или входная арка. Высота 2,5 м, ширина 3 м.</p>'),
  -- Product 4
  (4,'cs','Stropní výzdoba Elegance',    '<p>Romantická stropní instalace z bílých a zlatých balónků. Vytvoří kouzelnou atmosféru v prostoru recepce nebo tanečního sálu. Pokrytí až 20 m².</p>'),
  (4,'sk','Stropná výzdoba Elegance',    '<p>Romantická stropná inštalácia z bielych a zlatých balónov. Vytvorí čarovnú atmosféru v priestore recepcie alebo tanečnej sály. Pokrytie až 20 m².</p>'),
  (4,'en','Ceiling Decoration Elegance', '<p>Romantic ceiling installation of white and gold balloons. Creates a magical atmosphere in the reception or ballroom. Covers up to 20 m².</p>'),
  (4,'uk','Стельова декорація Elegance', '<p>Романтична стельова інсталяція з білих та золотих кульок. Створює чарівну атмосферу в залі прийомів або танцювальній залі. Покриття до 20 м².</p>'),
  (4,'ru','Потолочная декорация Elegance','<p>Романтическая потолочная инсталляция из белых и золотых шаров. Создаёт волшебную атмосферу в зале приёмов или танцевальном зале. Покрытие до 20 м².</p>'),
  -- Product 5
  (5,'cs','Jednorožcová party',          '<p>Pohádková výzdoba s motivem jednorožce. Pastelové balónky, fóliový jednorožec, duhová girlanda a dekorovaný stůl. Pro holčičky i kluky, kteří milují magii.</p>'),
  (5,'sk','Jednorožcová párty',          '<p>Rozprávková výzdoba s motívom jednorožca. Pastelové balóny, fóliový jednorožec, dúhová girlanda a ozdobený stôl.</p>'),
  (5,'en','Unicorn Party',               '<p>Magical unicorn-themed decoration. Pastel balloons, foil unicorn, rainbow garland and decorated table. For kids who love magic.</p>'),
  (5,'uk','Вечірка Єдинорог',            '<p>Казкові декорації з мотивом єдинорога. Пастельні кульки, фольговий єдиноріг, веселкова гірлянда та прикрашений стіл.</p>'),
  (5,'ru','Вечеринка Единорог',          '<p>Сказочные декорации с мотивом единорога. Пастельные шары, фольгированный единорог, радужная гирлянда и украшенный стол.</p>'),
  -- Product 6
  (6,'cs','Superhrdinové party',         '<p>Akční výzdoba pro malé superhrdiny. Balónky v barvách oblíbených superhrdinů, oblouk, štít z balónků a personalizovaný nápis.</p>'),
  (6,'sk','Superhrdinovia párty',        '<p>Akčná výzdoba pre malých superhrdinov. Balóny v farbách obľúbených superhrdinov, oblúk, štít z balónov a personalizovaný nápis.</p>'),
  (6,'en','Superheroes Party',           '<p>Action decoration for little superheroes. Balloons in favourite superhero colours, arch, balloon shield and personalised sign.</p>'),
  (6,'uk','Вечірка Супергерої',          '<p>Активні декорації для маленьких супергероїв. Кульки в кольорах улюблених супергероїв, арка, щит з кульок та персоналізований напис.</p>'),
  (6,'ru','Вечеринка Супергерои',        '<p>Экшн-декорации для маленьких супергероев. Шары в цветах любимых супергероев, арка, щит из шаров и персонализированная надпись.</p>'),
  -- Product 7
  (7,'cs','Firemní vstupní oblouk',      '<p>Reprezentativní vstupní oblouk v barvách vaší firmy. Průměr 4 m, možnost tisku loga na balónky (příplatek). Ideální na konference, veletrhy a firemní večírky.</p>'),
  (7,'sk','Firemný vstupný oblúk',       '<p>Reprezentatívny vstupný oblúk v farbách vašej firmy. Priemer 4 m, možnosť tlače loga na balóny (príplatok). Ideálny na konferencie, veľtrhy a firemné večierky.</p>'),
  (7,'en','Corporate Entrance Arch',     '<p>Representative entrance arch in your company colours. Diameter 4 m, logo printing on balloons available (surcharge). Ideal for conferences, trade fairs and corporate parties.</p>'),
  (7,'uk','Корпоративна вхідна арка',    '<p>Представницька вхідна арка в кольорах вашої компанії. Діаметр 4 м, можливість друку логотипу на кульках (доплата). Ідеально для конференцій, ярмарків та корпоративів.</p>'),
  (7,'ru','Корпоративная входная арка',  '<p>Представительская входная арка в цветах вашей компании. Диаметр 4 м, возможна печать логотипа на шарах (доплата). Идеально для конференций, выставок и корпоративов.</p>'),
  -- Product 8
  (8,'cs','Dekorace stolku',             '<p>Elegantní dekorace konferenčního nebo slavnostního stolku. Balónkové kytice, fóliové číslo nebo logo a stuhy v barvách dle přání.</p>'),
  (8,'sk','Dekorácia stolíka',           '<p>Elegantná dekorácia konferenčného alebo slávnostného stolíka. Balónové kytice, fóliové číslo alebo logo a stuhy v farbách podľa priania.</p>'),
  (8,'en','Table Decoration',            '<p>Elegant decoration for conference or celebration tables. Balloon bouquets, foil number or logo and ribbons in colours of your choice.</p>'),
  (8,'uk','Декорація столика',           '<p>Елегантна декорація конференційного або святкового столика. Букети з кульок, фольгована цифра або логотип і стрічки вибраних кольорів.</p>'),
  (8,'ru','Декорация столика',           '<p>Элегантная декорация конференционного или торжественного столика. Букеты из шаров, фольгированная цифра или логотип и ленты выбранных цветов.</p>')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- ------------------------------------------------------------
-- Gallery albums
-- ------------------------------------------------------------
INSERT IGNORE INTO `gallery_albums` (`id`, `slug`, `sort_order`) VALUES
  (1, 'narozeniny-2024', 1),
  (2, 'svatby-2024',     2),
  (3, 'detske-party',    3);

INSERT INTO `gallery_album_t` (`album_id`, `lang_code`, `name`, `description`) VALUES
  (1,'cs','Narozeninové oslavy 2024',  'Výběr z našich narozeninových dekorací.'),
  (1,'sk','Narodeninové oslavy 2024',  'Výber z našich narodeninových dekorácií.'),
  (1,'en','Birthday Parties 2024',     'A selection of our birthday decorations.'),
  (1,'uk','Святкування Дня Народження 2024','Добірка наших декорацій для днів народження.'),
  (1,'ru','Дни Рождения 2024',         'Подборка наших декораций для дней рождения.'),

  (2,'cs','Svatby 2024',               'Romantické svatební výzdoby z naší dílny.'),
  (2,'sk','Svadby 2024',               'Romantické svadobné výzdoby z našej dielne.'),
  (2,'en','Weddings 2024',             'Romantic wedding decorations from our workshop.'),
  (2,'uk','Весілля 2024',              'Романтичне весільне оформлення від нашої майстерні.'),
  (2,'ru','Свадьбы 2024',              'Романтическое свадебное оформление из нашей мастерской.'),

  (3,'cs','Dětské party',              'Pohádkové dekorace pro naše nejmenší zákazníky.'),
  (3,'sk','Detské párty',              'Rozprávkové dekorácie pre našich najmenších zákazníkov.'),
  (3,'en','Kids Parties',              'Magical decorations for our youngest customers.'),
  (3,'uk','Дитячі свята',              'Казкові декорації для наших наймолодших клієнтів.'),
  (3,'ru','Детские праздники',         'Сказочные декорации для наших самых маленьких клиентов.')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- ------------------------------------------------------------
-- Blog posts
-- ------------------------------------------------------------
INSERT IGNORE INTO `blog_posts` (`id`, `slug`, `status`, `published_at`) VALUES
  (1, 'jak-vybrat-baloncky-na-narozeniny', 'published', '2024-03-15 10:00:00'),
  (2, 'trendy-bajonkove-dekorace-2024',    'published', '2024-04-01 10:00:00'),
  (3, 'svatebni-bajonky-pruvodce',         'published', '2024-05-10 10:00:00');

INSERT INTO `blog_post_t` (`post_id`, `lang_code`, `title`, `body`, `meta_desc`) VALUES
  (1,'cs','Jak vybrat balónky na narozeniny',
    '<p>Výběr správných balónků na narozeninovou oslavu může být složitější, než se zdá. V tomto článku vám poradíme, jak zvolit správné barvy, tvary a velikosti pro nezapomenutelnou oslavu.</p><h2>Barvy</h2><p>Nejdůležitějším faktorem je barevné schéma. Doporučujeme zvolit 2–3 hlavní barvy a k nim jednu nebo dvě doplňkové.</p><h2>Velikosti</h2><p>Kombinace různých velikostí ballónků vytváří vizuálně zajímavé a dynamické dekorace.</p>',
    'Průvodce výběrem balónků na narozeninovou oslavu — barvy, tvary a tipy od profesionálů.'),
  (1,'sk','Ako vybrať balóny na narodeniny',
    '<p>Výber správnych balónov na narodeninová oslava môže byť zložitejší, než sa zdá. V tomto článku vám poradíme, ako zvoliť správne farby, tvary a veľkosti.</p><h2>Farby</h2><p>Odporúčame zvoliť 2–3 hlavné farby a k nim jednu alebo dve doplnkové.</p>',
    'Sprievodca výberom balónov na narodeninová oslava — farby, tvary a tipy od profesionálov.'),
  (1,'en','How to Choose Birthday Balloons',
    '<p>Choosing the right balloons for a birthday party can be trickier than it seems. In this article we share tips on colours, shapes and sizes for an unforgettable celebration.</p><h2>Colours</h2><p>We recommend choosing 2–3 main colours plus one or two accent colours.</p><h2>Sizes</h2><p>Combining different balloon sizes creates visually interesting and dynamic decorations.</p>',
    'A guide to choosing birthday balloons — colours, shapes and professional tips.'),
  (1,'uk','Як вибрати кульки на день народження',
    '<p>Вибір правильних кульок для святкування може бути складнішим, ніж здається. У цій статті ми поділимося порадами щодо кольорів, форм та розмірів.</p>',
    'Посібник з вибору кульок на день народження — кольори, форми та поради від професіоналів.'),
  (1,'ru','Как выбрать шары на день рождения',
    '<p>Выбор правильных шаров для праздника может быть сложнее, чем кажется. В этой статье мы поделимся советами по цветам, формам и размерам.</p>',
    'Руководство по выбору шаров на день рождения — цвета, формы и советы от профессионалов.'),

  (2,'cs','Trendy balónkové dekorace 2024',
    '<p>Rok 2024 přináší zajímavé novinky ve světě balónkových dekorací. Organické oblouky, pastelové barvy a kombinace fóliových a latexových balónků jsou letos největším hitem.</p><h2>Organické oblouky</h2><p>Nepravidelné, přírodně vyhlížející oblouky bez pevné konstrukce jsou stále populárnější.</p>',
    'Přehled nejmodernějších trendů v balónkové výzdobě pro rok 2024.'),
  (2,'sk','Trendy balónové dekorácie 2024',
    '<p>Rok 2024 prináša zaujímavé novinky vo svete balónových dekorácií. Organické oblúky, pastelové farby a kombinácia fóliových a latexových balónov sú tohtoročným hitom.</p>',
    'Prehľad najmodernejších trendov v balónovej výzdobe pre rok 2024.'),
  (2,'en','Balloon Decoration Trends 2024',
    '<p>2024 brings exciting new developments in the world of balloon décor. Organic arches, pastel colours and mixed foil-and-latex designs are this year''s biggest hits.</p><h2>Organic Arches</h2><p>Irregular, naturally-looking arches without a rigid frame are becoming increasingly popular.</p>',
    'An overview of the latest balloon decoration trends for 2024.'),
  (2,'uk','Тренди кулькових декорацій 2024',
    '<p>2024 рік приносить цікаві новинки у світі кулькових декорацій. Органічні арки, пастельні кольори та поєднання фольгованих та латексних кульок — головні хіти цього року.</p>',
    'Огляд найактуальніших трендів кулькового оформлення у 2024 році.'),
  (2,'ru','Тренды шаровых декораций 2024',
    '<p>2024 год приносит интересные новинки в мире шаровых декораций. Органические арки, пастельные цвета и сочетание фольгированных и латексных шаров — главные хиты этого года.</p>',
    'Обзор актуальных трендов в шаровом оформлении на 2024 год.'),

  (3,'cs','Svatební balónky — kompletní průvodce',
    '<p>Plánujete svatbu a přemýšlíte o balónkové výzdobě? Tento průvodce vám pomůže zorientovat se ve všech možnostech — od vstupního oblouku až po stropní instalaci v sále.</p><h2>Vstupní oblouk</h2><p>Vstupní oblouk je první věc, kterou vaši hosté uvidí. Věnujte mu proto zvláštní pozornost.</p>',
    'Kompletní průvodce svatební balónkovou výzdobou — od prvního nápadu po realizaci.'),
  (3,'sk','Svadobné balóny — kompletný sprievodca',
    '<p>Plánujete svadbu a uvažujete o balónovej výzdobe? Tento sprievodca vám pomôže zorientovať sa vo všetkých možnostiach.</p>',
    'Kompletný sprievodca svadobnou balónovou výzdobou — od prvého nápadu po realizáciu.'),
  (3,'en','Wedding Balloons — Complete Guide',
    '<p>Planning a wedding and considering balloon decorations? This guide will help you navigate all the options — from the entrance arch to the ceiling installation in the hall.</p><h2>Entrance Arch</h2><p>The entrance arch is the first thing your guests will see, so give it special attention.</p>',
    'A complete guide to wedding balloon decoration — from first idea to execution.'),
  (3,'uk','Весільні кульки — повний посібник',
    '<p>Плануєте весілля і думаєте про кулькове оформлення? Цей посібник допоможе вам розібратися у всіх можливостях.</p>',
    'Повний посібник з весільного кулькового оформлення — від першої ідеї до реалізації.'),
  (3,'ru','Свадебные шары — полное руководство',
    '<p>Планируете свадьбу и думаете об оформлении шарами? Это руководство поможет вам разобраться во всех возможностях.</p>',
    'Полное руководство по свадебному оформлению шарами — от первой идеи до реализации.')
ON DUPLICATE KEY UPDATE `title` = VALUES(`title`), `body` = VALUES(`body`);

-- ------------------------------------------------------------
-- Sample orders
-- ------------------------------------------------------------
INSERT IGNORE INTO `orders`
  (`id`, `order_number`, `status`, `customer_name`, `customer_email`, `customer_phone`, `pickup_date`, `total_amount`, `notes`) VALUES
  (1, 'ORD-2024-0001', 'completed', 'Jana Nováková',  'jana.novakova@example.com',  '+420 601 111 222', '2024-06-15', 1490.00, 'Přání: happy birthday Karolínka'),
  (2, 'ORD-2024-0002', 'paid',      'Tomáš Procházka','tomas.prochazka@example.com', '+420 602 333 444', '2024-07-20', 2490.00, 'Barvy: bílá a zlatá'),
  (3, 'ORD-2024-0003', 'pending',   'Monika Horáčková','monika.horáčková@example.com','+420 603 555 666', '2024-08-05',  890.00, NULL);

INSERT IGNORE INTO `order_items`
  (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `product_name_snapshot`) VALUES
  (1, 1, 2, 1, 1490.00, 'Narozeninová sada Premium'),
  (2, 2, 3, 1, 2490.00, 'Svatební oblouk Bílý sen'),
  (3, 3, 1, 1,  890.00, 'Narozeninová sada Classic');

-- ------------------------------------------------------------
-- Settings (update contact info)
-- ------------------------------------------------------------
INSERT INTO `settings` (`key`, `value`) VALUES
  ('site_name',       'BalonkyDecor'),
  ('contact_email',   'info@balonkydecor.cz'),
  ('contact_phone',   '+420 777 123 456'),
  ('contact_address', 'Praha, Česká republika')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);
