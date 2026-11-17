<?php

declare(strict_types=1);

namespace Modules\Imaging\Support;

/**
 * Example report texts a clinic can start from. They are examples only:
 * the clinic's reporting doctor must review every one before use.
 */
final class StarterTemplates
{
    /** @return array<string, array{label: string, templates: list<array{name: string, sex: string, findings: string, impression: string, advice?: string}>}> */
    public static function sets(): array
    {
        return [
            'usg-whole-abdomen' => ['label' => 'USG of whole abdomen', 'templates' => [
                ['name' => 'Normal', 'sex' => 'any',
                    'findings' => '<p><strong>Liver:</strong> Normal in size, shape and echotexture. No focal lesion. Intrahepatic bile ducts are not dilated.</p><p><strong>Gall bladder:</strong> Well distended. Wall is normal. No stone or sludge.</p><p><strong>Pancreas:</strong> Normal in size and echotexture.</p><p><strong>Spleen:</strong> Normal in size and echotexture.</p><p><strong>Kidneys:</strong> Both kidneys are normal in size, position and echotexture. Corticomedullary differentiation is maintained. No stone or hydronephrosis.</p><p><strong>Urinary bladder:</strong> Well filled. Wall is smooth. No stone.</p><p>No free fluid in the abdomen.</p>',
                    'impression' => '<p>Normal study of the whole abdomen.</p>'],
                ['name' => 'Fatty liver (grade I)', 'sex' => 'any',
                    'findings' => '<p><strong>Liver:</strong> Normal in size with mildly increased echogenicity. Diaphragm and intrahepatic vessels are clearly seen. No focal lesion.</p><p><strong>Gall bladder:</strong> Normal. No stone.</p><p><strong>Pancreas, spleen and both kidneys:</strong> Normal.</p><p><strong>Urinary bladder:</strong> Normal.</p><p>No free fluid in the abdomen.</p>',
                    'impression' => '<p>Grade I fatty change of the liver.</p>',
                    'advice' => 'Correlate with lipid profile and liver function tests.'],
                ['name' => 'Cholelithiasis', 'sex' => 'any',
                    'findings' => '<p><strong>Liver:</strong> Normal in size and echotexture.</p><p><strong>Gall bladder:</strong> Well distended. One echogenic focus measuring ... mm with posterior acoustic shadow is seen in the lumen. Wall thickness is normal.</p><p><strong>CBD:</strong> Not dilated.</p><p><strong>Pancreas, spleen and both kidneys:</strong> Normal.</p><p>No free fluid in the abdomen.</p>',
                    'impression' => '<p>Cholelithiasis.</p>',
                    'advice' => 'Surgical consultation.'],
            ]],
            'usg-lower-abdomen' => ['label' => 'USG of lower abdomen', 'templates' => [
                ['name' => 'Normal (male)', 'sex' => 'male',
                    'findings' => '<p><strong>Urinary bladder:</strong> Well filled. Wall is smooth. No stone or mass.</p><p><strong>Prostate:</strong> Normal in size (volume ... ml) and echotexture.</p><p>Post-void residual urine is insignificant.</p>',
                    'impression' => '<p>Normal study of the lower abdomen.</p>'],
                ['name' => 'Normal (female)', 'sex' => 'female',
                    'findings' => '<p><strong>Urinary bladder:</strong> Well filled. Wall is smooth. No stone or mass.</p><p><strong>Uterus:</strong> Anteverted, normal in size. Myometrium is homogeneous. Endometrial thickness ... mm.</p><p><strong>Ovaries:</strong> Both ovaries are normal in size and echotexture.</p><p>No free fluid in the pouch of Douglas.</p>',
                    'impression' => '<p>Normal study of the lower abdomen.</p>'],
            ]],
            'usg-kub' => ['label' => 'USG of KUB', 'templates' => [
                ['name' => 'Normal', 'sex' => 'any',
                    'findings' => '<p><strong>Right kidney:</strong> Normal in size (... cm), position and echotexture. No stone or hydronephrosis.</p><p><strong>Left kidney:</strong> Normal in size (... cm), position and echotexture. No stone or hydronephrosis.</p><p><strong>Ureters:</strong> Not dilated.</p><p><strong>Urinary bladder:</strong> Well filled. Wall is smooth. No stone.</p>',
                    'impression' => '<p>Normal study of the kidneys, ureters and bladder.</p>'],
            ]],
            'usg-pregnancy' => ['label' => 'USG of pregnancy profile', 'templates' => [
                ['name' => 'Single live intrauterine pregnancy', 'sex' => 'female',
                    'findings' => '<p>A single live fetus is seen in the uterine cavity.</p><table><tr><td>Presentation</td><td>...</td></tr><tr><td>Fetal heart rate</td><td>... beats/min</td></tr><tr><td>BPD</td><td>... mm</td></tr><tr><td>FL</td><td>... mm</td></tr><tr><td>Placenta</td><td>..., grade ...</td></tr><tr><td>Amniotic fluid</td><td>Adequate</td></tr><tr><td>Estimated fetal weight</td><td>... g</td></tr></table>',
                    'impression' => '<p>Single live intrauterine pregnancy of about ... weeks ... days.</p><p>Expected date of delivery: ...</p>'],
            ]],
            'xray-chest' => ['label' => 'X-ray of chest (PA view)', 'templates' => [
                ['name' => 'Normal', 'sex' => 'any',
                    'findings' => '<p>Both lung fields are clear.</p><p>Both hila are normal.</p><p>Cardiac size and shape are within normal limits.</p><p>Both costophrenic angles are clear.</p><p>Bony thorax appears normal.</p>',
                    'impression' => '<p>Normal chest X-ray.</p>'],
            ]],
            'ecg' => ['label' => 'ECG', 'templates' => [
                ['name' => 'Normal', 'sex' => 'any',
                    'findings' => '<table><tr><td>Rhythm</td><td>Sinus</td></tr><tr><td>Heart rate</td><td>... beats/min</td></tr><tr><td>PR interval</td><td>... sec</td></tr><tr><td>QRS duration</td><td>... sec</td></tr><tr><td>Axis</td><td>Normal</td></tr><tr><td>ST-T</td><td>No significant change</td></tr></table>',
                    'impression' => '<p>ECG within normal limits.</p>'],
            ]],
        ];
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_map(fn (array $set): string => $set['label'].' ('.count($set['templates']).')', self::sets());
    }

    /** @return list<array{title: string, body: string}> */
    public static function phrases(): array
    {
        return [
            ['title' => 'No free fluid', 'body' => 'No free fluid in the abdomen.'],
            ['title' => 'No focal lesion', 'body' => 'No focal lesion is seen.'],
            ['title' => 'Bladder not filled', 'body' => 'The urinary bladder was not adequately filled at the time of examination.'],
            ['title' => 'Gas obscured', 'body' => 'Visualisation was limited by bowel gas.'],
            ['title' => 'Clinical correlation', 'body' => 'Clinical correlation is suggested.'],
        ];
    }
}
