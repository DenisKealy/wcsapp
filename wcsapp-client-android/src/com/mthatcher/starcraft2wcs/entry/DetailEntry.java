package com.mthatcher.starcraft2wcs.entry;

import java.util.ArrayList;

import android.graphics.drawable.Drawable;

public class DetailEntry implements Entry{
	
	private ArrayList<MapDetail> maps;
	private String player1Name;
	private String player2Name;
	private Drawable player1Race;
	private Drawable player2Race;
	private Drawable player1Flag;
	private Drawable player2Flag;
	private int player1Wins;
	private int player2Wins;

	public DetailEntry(String p1Name, String p2Name, Drawable p1Race,
			Drawable p2Race, Drawable p1Flag, Drawable p2Flag,
			ArrayList<MapDetail> mapDetails) {
		this.player1Name = p1Name;
		this.player2Name = p2Name;
		this.player1Race = p1Race;
		this.player2Race = p2Race;
		this.player1Flag = p1Flag;
		this.player2Flag = p2Flag;
		maps = mapDetails;
		player1Wins = 0;
		player2Wins = 0;
	}

	public String getPlayer1Name() {
		return player1Name;
	}

	public String getPlayer2Name() {
		return player2Name;
	}

	public Drawable getPlayer1Race() {
		return player1Race;
	}

	public Drawable getPlayer2Race() {
		return player2Race;
	}

	public Drawable getPlayer1Flag() {
		return player1Flag;
	}

	public Drawable getPlayer2Flag() {
		return player2Flag;
	}

	public ArrayList<MapDetail> getMaps() {
		return maps;
	}
	
	public int getPlayer1Wins() {
		return player1Wins;
	}

	public int getPlayer2Wins() {
		return player2Wins;
	}

	public boolean doesPlayer1Win() {
		return player1Wins > player2Wins;
	}
	
	public boolean doesPlayer2Win() {
		return player2Wins > player1Wins;
	}

	public void addMapDetail(MapDetail map) {
		if(map.doesPlayer1Win())
			player1Wins++;
		if(map.doesPlayer2Win())
			player2Wins++;
		maps.add(map);
	}

	public class MapDetail {
		private String mapName;
		private boolean p1Wins;
		private boolean p2Wins;

		public MapDetail(String mapName, String mapWinner) {
			this.mapName = mapName;
			if (mapWinner != null && mapWinner.equals("1")) {
				p1Wins = true;
				p2Wins = false;
			} else if (mapWinner != null && mapWinner.equals("2")) {
				p1Wins = false;
				p2Wins = true;
			} else {
				p1Wins = false;
				p2Wins = false;
			}
		}

		public String getMapName() {
			return mapName;
		}

		public boolean doesPlayer1Win() {
			return p1Wins;
		}

		public boolean doesPlayer2Win() {
			return p2Wins;
		}
	}

	public int getP1BackgroundColor() {
		if(doesPlayer1Win())
			return EntryUtil.getWinnerColor();
		else
			return EntryUtil.getLoserColor();
	}
	
	public int getP2BackgroundColor() {
		if(doesPlayer2Win())
			return EntryUtil.getWinnerColor();
		else
			return EntryUtil.getLoserColor();
	}
}
