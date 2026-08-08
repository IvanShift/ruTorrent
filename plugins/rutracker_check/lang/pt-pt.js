/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Portuguese (Portugal) language file.
 *
 * Author:
 */

 theUILang.checkTorrent		= "Verificar por atualizações";
 theUILang.chkHdr		= "Verificação de Atualização de Torrent";
 theUILang.checkedAt		= "Última verificação";
 theUILang.checkedResult	= "Resultado";
 theUILang.chkResults		= [
 				  "Em andamento",
 				  "Atualizado",
 				  "Nenhuma atualização necessária",
 				  "Provavelmente apagado",
 				  "Erro ao acessar o rastreador",
 				  "Erro ao interagir com o rTorrent",
 				  "Não há necessidade",
 				  "Ignorado",
 				  "À espera de metadados",
 				  "Absorvido por outro tópico — resolver manualmente"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"A versão atual deste tópico já está no cliente: %s",
 				  "deleting":	"Tópico ausente da lista do fórum; ciclo de confirmação %s",
 				  "topic-status": "Estado do tópico %s: fechado, não aprovado ou duplicado",
 				  "fuse":	"O rastreador %s parece estar indisponível; a verificação foi adiada"
 				  };

thePlugins.get("rutracker_check").langLoaded();
