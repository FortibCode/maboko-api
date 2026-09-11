#!/bin/sh
# Lance nginx seulement quand php-fpm accepte les connexions.
#
# Supervisor demarre ses programmes l'un apres l'autre sans attendre qu'ils
# soient prets. nginx ouvrait donc son port avant que php-fpm n'ecoute, et
# toute requete arrivee dans cet intervalle — la sonde de sante de Render en
# premier — repartait en 502 « Connection refused ».
#
# Le test se fait avec PHP plutot qu'avec « nc » : l'interpreteur est de
# toute facon dans l'image, et l'applet nc de busybox n'accepte pas partout
# l'option -z.

set -e

echo "[maboko] attente de php-fpm"

essais=0
until php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);' 2>/dev/null; do
    essais=$((essais + 1))

    if [ "$essais" -gt 150 ]; then
        echo "[maboko] php-fpm ne repond toujours pas apres 30 s, nginx demarre quand meme"
        break
    fi

    sleep 0.2
done

echo "[maboko] php-fpm est pret, demarrage de nginx"

exec nginx -g "daemon off;"
