CREATE TABLE IF NOT EXISTS services (
  id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  price_from INT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS service_t (
  service_id INT NOT NULL,
  lang_code VARCHAR(5) NOT NULL,
  name VARCHAR(255) NOT NULL,
  description TEXT NULL,
  features TEXT NULL,
  PRIMARY KEY (service_id, lang_code),
  CONSTRAINT fk_service_t_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: imported from the live /services page content (2026-07-09)
INSERT IGNORE INTO services (id, price_from, sort_order) VALUES (1, 890, 10);
INSERT IGNORE INTO services (id, price_from, sort_order) VALUES (2, 1890, 20);
INSERT IGNORE INTO services (id, price_from, sort_order) VALUES (3, 1190, 30);
INSERT IGNORE INTO services (id, price_from, sort_order) VALUES (4, 990, 40);
INSERT IGNORE INTO services (id, price_from, sort_order) VALUES (5, 1290, 50);
INSERT IGNORE INTO services (id, price_from, sort_order) VALUES (6, 890, 60);
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (1, 'cs', 'Narozeninové oslavy', 'Balónková výzdoba pro narozeninové oslavy všech věkových kategorií. Oblouky, girlandy, dekorovaný stůl a personalizovaný nápis přesně podle vašich přání.', 'Balónkový oblouk nebo girlanda
Dekorovaný narozeninový stůl
Fóliová čísla a narozeninový nápis
Fotokoutek na přání');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (2, 'cs', 'Svatební výzdoba', 'Organické oblouky, romantické stropní instalace a dekorovaný sál pro nezapomenutelný svatební den.', 'Vstupní nebo oltářní oblouk
Stropní balónková instalace
Dekorace stolů a recepce
Barvy a styl zcela na přání');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (3, 'cs', 'Dětské party', 'Pohádkové tematické dekorace pro nejmenší oslavence. Jednorožci, superhrdinové, princezny nebo oblíbené pohádkové postavy.', 'Tematická výzdoba dle přání dítěte
Balónkový oblouk a girlandy
Dekorovaný dortový stůl
Balónky jako dárky pro děti');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (4, 'cs', 'Firemní akce', 'Profesionální výzdoba v barvách vaší firmy pro konference, veletrhy a firemní večírky. Možnost tisku loga na balónky.', 'Vstupní oblouk v barvách firmy
Výzdoba pódia a přednáškového prostoru
Dekorace stolů a cateringu
Potisk loga na balónky (příplatek)');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (5, 'cs', 'Baby shower a křtiny', 'Jemné pastelové dekorace pro přivítání nového přírůstku do rodiny. Možnost gender reveal instalace.', 'Pastelová balónková výzdoba
Gender reveal instalace na přání
Dekorovaný uvítací stůl
Nápis se jménem miminka');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (6, 'cs', 'Maturita a promoce', 'Oslavte úspěch vašich absolventů s radostnou výzdobou. Balónky ve školních barvách a oblouk pro slavnostní fotografii.', 'Balónkový oblouk pro společné foto
Fóliové číslice ročníku ukončení
Girlandy a dekorační prvky
Barvy dle přání školy nebo třídy');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (1, 'sk', 'Narodeninové oslavy', 'Balónová výzdoba pre narodeninové oslavy všetkých vekových kategórií. Oblúky, girlandy, ozdobený stôl a personalizovaný nápis presne podľa vašich prianí.', 'Balónový oblúk alebo girlanda
Ozdobený narodeninový stôl
Fóliové čísla a narodeninový nápis
Fotokútik na prianie');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (2, 'sk', 'Svadobná výzdoba', 'Organické oblúky, romantické stropné inštalácie a ozdobená sála pre nezabudnuteľný svadobný deň.', 'Vstupný alebo oltárny oblúk
Stropná balónová inštalácia
Dekorácia stolov a recepcie
Farby a štýl celkom na prianie');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (3, 'sk', 'Detské párty', 'Rozprávkové tematické dekorácie pre najmenších oslavencov. Jednorožci, superhrdinovia, princezné alebo obľúbené rozprávkové postavičky.', 'Tematická výzdoba podľa priania dieťaťa
Balónový oblúk a girlandy
Ozdobený tortový stôl
Balóny ako darčeky pre deti');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (4, 'sk', 'Firemné akcie', 'Profesionálna výzdoba v farbách vašej firmy pre konferencie, veľtrhy a firemné večierky. Možnosť tlače loga na balóny.', 'Vstupný oblúk v farbách firmy
Výzdoba pódia a prednáškového priestoru
Dekorácia stolov a cateringu
Potlač loga na balóny (príplatok)');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (5, 'sk', 'Baby shower a krstiny', 'Jemné pastelové dekorácie pre privítanie nového prírastku do rodiny. Možnosť gender reveal inštalácie.', 'Pastelová balónová výzdoba
Gender reveal inštalácia na prianie
Ozdobený uvítací stôl
Nápis s menom bábätka');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (6, 'sk', 'Maturita a promócie', 'Oslávte úspech vašich absolventov s radostnou výzdobou. Balóny v školských farbách a oblúk pre slávnostné foto.', 'Balónový oblúk pre spoločné foto
Fóliové číslice ročníka ukončenia
Girlandy a dekoratívne prvky
Farby podľa priania školy alebo triedy');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (1, 'en', 'Birthday Celebrations', 'Balloon decoration for birthday parties of all ages. Arches, garlands, decorated tables and personalised signs exactly to your wishes.', 'Balloon arch or garland
Decorated birthday table
Foil number balloons and birthday sign
Photo corner on request');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (2, 'en', 'Wedding Decoration', 'Organic arches, romantic ceiling installations and a decorated hall for an unforgettable wedding day.', 'Entrance or altar arch
Balloon ceiling installation
Table and reception decoration
Colours and style to your taste');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (3, 'en', 'Kids Parties', 'Magical themed decorations for the little ones. Unicorns, superheroes, princesses or favourite fairy-tale characters.', 'Themed decoration of your choice
Balloon arch and garlands
Decorated cake table
Take-away balloons for children');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (4, 'en', 'Corporate Events', 'Professional decoration in your company colours for conferences, trade fairs and corporate parties. Logo printing on balloons available.', 'Entrance arch in company colours
Stage and lecture area decoration
Table and catering decoration
Logo printing on balloons (surcharge)');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (5, 'en', 'Baby Shower & Christening', 'Soft pastel decorations for welcoming a new family member. Gender reveal installation available on request.', 'Pastel balloon decoration
Gender reveal installation on request
Decorated welcome table
Sign with the baby name');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (6, 'en', 'Graduation Celebrations', 'Celebrate your graduates in style. Balloons in school colours and an arch for the ceremonial group photo.', 'Balloon arch for group photo
Foil graduation year numbers
Garlands and decorative elements
Colours to match school or class');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (1, 'uk', 'Святкування днів народження', 'Кулькове оформлення для днів народження будь-якого віку. Арки, гірлянди, прикрашений стіл та персоналізований напис за вашим бажанням.', 'Арка або гірлянда з кульок
Прикрашений святковий стіл
Фольговані цифри та напис
Фотозона за бажанням');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (2, 'uk', 'Весільне оформлення', 'Органічні арки, романтичні стельові інсталяції та прикрашена зала для незабутнього весільного дня.', 'Вхідна або вівтарна арка
Стельова кулькова інсталяція
Декорація столів та рецепції
Кольори та стиль за вашим смаком');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (3, 'uk', 'Дитячі свята', 'Казкові тематичні декорації для найменших іменинників. Єдинороги, супергерої, принцеси або улюблені казкові персонажі.', 'Тематичне оформлення за бажанням дитини
Арка та гірлянди з кульок
Прикрашений стіл для торта
Кульки як подарунки для дітей');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (4, 'uk', 'Корпоративні заходи', 'Професійне оформлення в кольорах вашої компанії для конференцій, ярмарків та корпоративів. Можливість друку логотипу на кульках.', 'Вхідна арка в кольорах компанії
Оформлення сцени та лекційного простору
Декорація столів та кейтерингу
Друк логотипу на кульках (доплата)');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (5, 'uk', 'Baby shower та хрестини', 'Ніжні пастельні декорації для привітання нового члена родини. Можлива інсталяція gender reveal.', 'Пастельне кулькове оформлення
Інсталяція gender reveal за бажанням
Прикрашений привітальний стіл
Напис з іменем малюка');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (6, 'uk', 'Випускні та вручення дипломів', 'Відсвяткуйте успіх ваших випускників з радісним оформленням. Кульки в кольорах школи та арка для урочистого фото.', 'Арка з кульок для групового фото
Фольговані цифри випускного року
Гірлянди та декоративні елементи
Кольори за бажанням школи або класу');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (1, 'ru', 'Праздники дней рождения', 'Оформление шарами для дней рождения любого возраста. Арки, гирлянды, украшенный стол и персонализированная надпись по вашему желанию.', 'Арка или гирлянда из шаров
Украшенный праздничный стол
Фольгированные цифры и надпись
Фотозона по желанию');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (2, 'ru', 'Свадебное оформление', 'Органические арки, романтические потолочные инсталляции и украшенный зал для незабываемого свадебного дня.', 'Входная или алтарная арка
Потолочная инсталляция из шаров
Декорация столов и рецепции
Цвета и стиль по вашему вкусу');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (3, 'ru', 'Детские праздники', 'Сказочные тематические декорации для маленьких именинников. Единороги, супергерои, принцессы или любимые сказочные персонажи.', 'Тематическое оформление по желанию ребёнка
Арка и гирлянды из шаров
Украшенный стол для торта
Шары в подарок детям');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (4, 'ru', 'Корпоративные мероприятия', 'Профессиональное оформление в цветах вашей компании для конференций, выставок и корпоративов. Возможна печать логотипа на шарах.', 'Входная арка в цветах компании
Оформление сцены и лекционного пространства
Декорация столов и кейтеринга
Печать логотипа на шарах (доплата)');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (5, 'ru', 'Baby shower и крестины', 'Нежные пастельные декорации для встречи нового члена семьи. Возможна инсталляция gender reveal.', 'Пастельное оформление из шаров
Инсталляция gender reveal по желанию
Украшенный приветственный стол
Надпись с именем малыша');
INSERT IGNORE INTO service_t (service_id, lang_code, name, description, features) VALUES (6, 'ru', 'Выпускные и вручение дипломов', 'Отметьте успех ваших выпускников с радостным оформлением. Шары в цветах школы и арка для торжественного фото.', 'Арка из шаров для группового фото
Фольгированные цифры выпускного года
Гирлянды и декоративные элементы
Цвета по желанию школы или класса');
