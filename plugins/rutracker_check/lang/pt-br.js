/*
 * PLUGIN RUTRACKER_CHECK
 *
 * Portuguese (Brazil) language file.
 *
 * Author:
 */

 theUILang.checkTorrent		= "Verificar Atualização";
 theUILang.chkHdr		= "Verificação de Atualização do Torrent";
 theUILang.checkedAt		= "Última Verificação";
 theUILang.checkedResult	= "Resultado";
 theUILang.chkResults		= [
 				  "Em progresso",
 				  "Atualizado",
 				  "Nenhuma atualização necessária",
 				  "Provavelmente excluído",
 				  "Erro ao acessar o rastreador",
 				  "Erro ao interagir com o rTorrent",
 				  "Não é necessário",
 				  "Ignorado",
 				  "Aguardando metadados",
 				  "Absorvido por outro tópico — resolver manualmente"
 				  ];
 theUILang.chkMessages		= {
 				  "superseded":	"A versão atual deste tópico já está no cliente: %s",
 				  "deleting":	"Tópico ausente da lista do fórum; ciclo de confirmação %s",
 				  "topic-status": "Status do tópico %s: fechado, não aprovado ou duplicado",
 				  "fuse":	"O rastreador %s parece estar indisponível; a verificação foi adiada"
 				  };

thePlugins.get("rutracker_check").langLoaded();
