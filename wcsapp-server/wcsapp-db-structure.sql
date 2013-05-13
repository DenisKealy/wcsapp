CREATE TABLE "games" ("id" INTEGER PRIMARY KEY  NOT NULL ,"mapname" TEXT,"mapwinner" BOOL,"vodlink" TEXT,"matchid" INTEGER NOT NULL  DEFAULT (null) );
CREATE TABLE "matches" ("id" INTEGER PRIMARY KEY  NOT NULL ,"winner" TEXT,"player1name" TEXT,"player2name" TEXT,"player1race" TEXT,"player2race" TEXT,"player1flag" TEXT,"player2flag" TEXT,"numgames" INTEGER DEFAULT (null) ,"matchname" TEXT,"scheduleid" INTEGER NOT NULL  DEFAULT (null) , "matchnum" INTEGER);
CREATE TABLE "schedule" ("id" INTEGER PRIMARY KEY NOT NULL ,"time" INTEGER,"division" TEXT,"region" TEXT,"name" TEXT, "round" TEXT);
