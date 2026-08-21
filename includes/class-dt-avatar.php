<?php
if (!defined('ABSPATH')) exit;

class DT_Avatar {
    private const OPTION = 'dt_avatar_messages';

    public static function defaults(): array {
        return [
            'welcome' => ['image'=>'01-powitanie.png','label'=>'Powitanie','text'=>'No, jesteś! Piłka czeka, typy same się nie zrobią.'],
            'thinking' => ['image'=>'02-myslenie.png','label'=>'Myślenie','text'=>'Spokojnie. Nawet trener czasem patrzy w tablicę trochę dłużej.'],
            'saved' => ['image'=>'03-typowanie-zapisany.png','label'=>'Kupon zapisany','text'=>'Kupon zapisany. Teraz pozostaje udawać, że wszystko było przemyślane.'],
            'perfect' => ['image'=>'04-idealny-typowanie.png','label'=>'Idealny kupon','text'=>'Komplet trafień! Możesz przez chwilę mówić, że znasz się najlepiej.'],
            'warning' => ['image'=>'05-ostrzezenie.png','label'=>'Niepełny kupon','text'=>'Halo, zostały puste mecze. Piłka nie wybierze za Ciebie.'],
            'closed' => ['image'=>'06-kolejka-zamknieta.png','label'=>'Kolejka zamknięta','text'=>'Koniec typowania. Gwizdek był, reklamacji nie przyjmuję.'],
            'missed' => ['image'=>'07-nietrafiony-typ.png','label'=>'Nietrafiony typ','text'=>'Nie weszło. Koszykówka bywa złośliwa, ale następna kolejka już czeka.'],
            'bonus' => ['image'=>'08-mecz-bonus.png','label'=>'Mecz BONUS','text'=>'BONUS na parkiecie. Tu odwaga może ważyć trochę więcej.'],
        ];
    }

    public static function messages(): array {
        $saved = (array) get_option(self::OPTION, []);
        $out = self::defaults();
        foreach ($out as $key => &$item) {
            if (isset($saved[$key]) && is_string($saved[$key]) && trim($saved[$key]) !== '') {
                $item['text'] = sanitize_text_field($saved[$key]);
            }
            $item['url'] = DT_URL . 'assets/img/artur-bot/' . $item['image'];
        }
        unset($item);
        return $out;
    }

    public static function save(array $input): void {
        $clean = [];
        foreach (self::defaults() as $key => $item) {
            $value = sanitize_text_field(wp_unslash((string)($input[$key] ?? '')));
            $clean[$key] = $value !== '' ? $value : $item['text'];
        }
        update_option(self::OPTION, $clean, false);
    }
}
